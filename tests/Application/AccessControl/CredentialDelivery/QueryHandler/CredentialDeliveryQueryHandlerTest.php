<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindCredentialDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindCredentialDeliveryStatusHandler::class)]
#[CoversClass(FindDueCredentialDeliveriesHandler::class)]
#[CoversClass(CredentialDeliveryStatusView::class)]
#[CoversClass(FindCredentialDeliveryStatus::class)]
#[CoversClass(FindDueCredentialDeliveries::class)]
final class CredentialDeliveryQueryHandlerTest extends TestCase
{
    public function test_due_query_merges_all_families_in_bounded_deterministic_order(): void
    {
        [$activation, $passwordReset, $emailChange] = $this->grants();
        $activationRepository = new InMemoryActivationGrantRepository();
        $passwordResetRepository = new InMemoryPasswordResetGrants();
        $emailChangeRepository = new InMemoryEmailChangeGrantRepository();
        self::assertTrue($activationRepository->add($activation));
        self::assertTrue($passwordResetRepository->add($passwordReset));
        self::assertTrue($emailChangeRepository->add($emailChange));
        $query = new FindDueCredentialDeliveries(new DateTimeImmutable('2026-08-23T11:00:00+00:00'), 2);
        $handler = new FindDueCredentialDeliveriesHandler(
            $activationRepository,
            $passwordResetRepository,
            $emailChangeRepository
        );

        $due = $handler->handle(QueryMessage::create($query));

        $expectedIds = [
            $activation->getDelivery()->getId()->toString(),
            $passwordReset->getDelivery()->getId()->toString(),
            $emailChange->getDelivery()->getId()->toString()
        ];
        sort($expectedIds);
        self::assertSame(array_slice($expectedIds, 0, 2), array_map(
            static fn ($work): string => $work->getDeliveryId()->toString(),
            $due
        ));
        self::assertCount(2, $due);
        self::assertContains($due[0]->getUserId()->toString(), [
            $activation->getUserId()->toString(),
            $passwordReset->getUserId()->toString(),
            $emailChange->getUserId()->toString()
        ]);
        self::assertSame(FindDueCredentialDeliveries::class, $handler::queryRegistration());
        self::assertEquals($query, FindDueCredentialDeliveries::fromArray($query->toArray()));
        self::assertEquals(new DateTimeImmutable('2026-08-23T11:00:00+00:00'), $query->getAt());
        self::assertSame(2, $query->getLimit());
    }

    public function test_status_query_returns_safe_complete_state_for_every_family(): void
    {
        [$activation, $passwordReset, $emailChange] = $this->grants();
        $activationRepository = new InMemoryActivationGrantRepository();
        $passwordResetRepository = new InMemoryPasswordResetGrants();
        $emailChangeRepository = new InMemoryEmailChangeGrantRepository();
        self::assertTrue($activationRepository->add($activation));
        self::assertTrue($passwordResetRepository->add($passwordReset));
        self::assertTrue($emailChangeRepository->add($emailChange));
        $handler = new FindCredentialDeliveryStatusHandler(
            $activationRepository,
            $passwordResetRepository,
            $emailChangeRepository
        );

        foreach ([$activation, $passwordReset, $emailChange] as $grant) {
            $query = new FindCredentialDeliveryStatus(
                $grant->purpose(),
                $grant->getDelivery()->getId()->toString()
            );
            $view = $handler->handle(QueryMessage::create($query));
            self::assertInstanceOf(CredentialDeliveryStatusView::class, $view);
            self::assertSame($grant->purpose(), $view->getPurpose());
            self::assertSame($grant->getDelivery()->getId()->toString(), $view->getDeliveryId());
            self::assertTrue($grant->getUserId()->equals($view->getUserId()));
            self::assertSame(0, $view->getRevision());
            self::assertSame(CredentialDeliveryStatus::PENDING, $view->getStatus());
            self::assertEquals($grant->getDelivery()->getDueAt(), $view->getDueAt());
            self::assertEquals($grant->getExpiresAt(), $view->getExpiresAt());
            self::assertSame(0, $view->getAttemptCount());
            self::assertNull($view->getLastAttemptAt());
            self::assertNull($view->getLastOutcomeAt());
            self::assertNull($view->getLastFailure());
            self::assertArrayNotHasKey('email', $view->toArray());
            self::assertArrayNotHasKey('ciphertext', $view->toArray());
            self::assertEquals($query, FindCredentialDeliveryStatus::fromArray($query->toArray()));
        }

        self::assertSame(FindCredentialDeliveryStatus::class, $handler::queryRegistration());
        self::assertNull($handler->handle(QueryMessage::create(new FindCredentialDeliveryStatus(
            'activation',
            $emailChange->getDelivery()->getId()->toString()
        ))));
    }

    public function test_queries_reject_missing_unsupported_or_unbounded_input(): void
    {
        foreach (
            [
            static fn (): FindDueCredentialDeliveries => new FindDueCredentialDeliveries(new DateTimeImmutable(), 0),
            static fn (): FindDueCredentialDeliveries => FindDueCredentialDeliveries::fromArray([]),
            static fn (): FindCredentialDeliveryStatus => new FindCredentialDeliveryStatus('unsupported', 'id'),
            static fn (): FindCredentialDeliveryStatus => new FindCredentialDeliveryStatus('activation', ''),
            static fn (): FindCredentialDeliveryStatus => FindCredentialDeliveryStatus::fromArray([])
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Invalid credential-delivery query input was accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array{ActivationGrant, PasswordResetGrant, EmailChangeGrant} */
    private function grants(): array
    {
        $issuedAt = new DateTimeImmutable('2026-08-23T11:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $email = EmailAddress::fromString('alice@example.test');

        return [
            ActivationGrant::issue(
                UserId::generate(),
                ActivationCredential::fromString('activate'),
                $issuedAt,
                $expiresAt,
                $email,
                'ciphertext:activate'
            ),
            PasswordResetGrant::issue(
                UserId::generate(),
                PasswordResetCredential::fromString('reset'),
                $issuedAt,
                $expiresAt,
                $email,
                'ciphertext:reset'
            ),
            EmailChangeGrant::issue(
                UserId::generate(),
                EmailChangeCredential::fromString('change'),
                $issuedAt,
                $expiresAt,
                $email,
                'ciphertext:change'
            )
        ];
    }
}
