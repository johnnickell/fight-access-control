<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ExpireInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ExpireInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\FabricatedEmailChangeGrant;
use Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant\ExtensibleActivationGrant;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetGrant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindExpiredCredentialDeliveriesHandler::class)]
#[CoversClass(ExpiredCredentialDelivery::class)]
#[CoversClass(ExpireInvitationDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ExpireEmailChangeHandler::class)]
final class ExpiredCredentialOrderingTest extends TestCase
{
    public function test_each_family_orders_contrasting_instants_and_ids_before_limiting(): void
    {
        $fixture = $this->orderedFixture();
        foreach ([$fixture->activation, $fixture->email, $fixture->reset] as $repository) {
            $actual = $repository->findExpired($fixture->expiresAt->modify('+3 minutes'), 2);
            self::assertSame([
                '33333333-3333-4333-8333-333333333333',
                '11111111-1111-4111-8111-111111111111'
            ], array_map(
                static fn (ExpiredCredentialDelivery $item): string => $item->getDeliveryId()->toString(),
                $actual
            ));
            self::assertEquals($fixture->expiresAt, $actual[0]->getExpiresAt());
            self::assertEquals($fixture->expiresAt->modify('+1 minute'), $actual[1]->getExpiresAt());
            $all = $repository->findExpired($fixture->expiresAt->modify('+3 minutes'), 100);
            self::assertSame([
                '33333333-3333-4333-8333-333333333333',
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '44444444-4444-4444-8444-444444444444'
            ], array_map(
                static fn (ExpiredCredentialDelivery $item): string => $item->getDeliveryId()->toString(),
                $all
            ));
            self::assertEquals($fixture->expiresAt->modify('+1 minute'), $all[2]->getExpiresAt());
            self::assertEquals($fixture->expiresAt->modify('+2 minutes'), $all[3]->getExpiresAt());
        }

        self::assertSame(0, $fixture->unitOfWork->transactions);
        self::assertSame([], $fixture->events->events());
    }

    public function test_actual_mixed_family_pages_are_read_only_rediscoverable_and_drain_in_hand_order(): void
    {
        $fixture = $this->orderedFixture();
        $at = $fixture->expiresAt->modify('+3 minutes');
        $query = QueryMessage::create(new FindExpiredCredentialDeliveries($at, 2));
        // Independently hand-ordered: instant dominates ID, which dominates purpose.
        // Several pages split ties across families; neither ID-first nor purpose-first can pass.
        $expectedPages = [
            ['activation:33333333-3333-4333-8333-333333333333', 'email_change:33333333-3333-4333-8333-333333333333'],
            ['password_reset:33333333-3333-4333-8333-333333333333', 'activation:11111111-1111-4111-8111-111111111111'],
            [
                'email_change:11111111-1111-4111-8111-111111111111',
                'password_reset:11111111-1111-4111-8111-111111111111'
            ],
            ['activation:22222222-2222-4222-8222-222222222222', 'email_change:22222222-2222-4222-8222-222222222222'],
            ['password_reset:22222222-2222-4222-8222-222222222222', 'activation:44444444-4444-4444-8444-444444444444'],
            ['email_change:44444444-4444-4444-8444-444444444444', 'password_reset:44444444-4444-4444-8444-444444444444']
        ];
        foreach ($expectedPages as $index => $expected) {
            $before = [$fixture->activation->all(), $fixture->email->all(), $fixture->reset->all()];
            $users = array_map(self::userState(...), $fixture->users->all());
            $transactions = $fixture->unitOfWork->transactions;
            $events = $fixture->events->events();
            $page = $fixture->discovery()->handle($query);
            self::assertSame($expected, array_map(static fn (ExpiredCredentialDelivery $item): string =>
                $item->getPurpose().':'.$item->getDeliveryId()->toString(), $page));
            self::assertEquals($page, $fixture->discovery()->handle($query));
            self::assertSame($before, [$fixture->activation->all(), $fixture->email->all(), $fixture->reset->all()]);
            self::assertSame($users, array_map(self::userState(...), $fixture->users->all()));
            self::assertSame($transactions, $fixture->unitOfWork->transactions);
            self::assertSame($events, $fixture->events->events());
            foreach ($page as $item) {
                $command = match ($item->getPurpose()) {
                    'activation' => new ExpireInvitationDelivery(
                        'worker:ordered',
                        $item->getUserId(),
                        ActivationDeliveryId::fromString($item->getDeliveryId()->toString()),
                        $at
                    ),
                    'password_reset' => new ExpirePasswordResetDelivery(
                        'worker:ordered',
                        $item->getUserId(),
                        PasswordResetDeliveryId::fromString($item->getDeliveryId()->toString()),
                        $at
                    ),
                    default => new ExpireEmailChange(
                        'worker:ordered',
                        $item->getUserId(),
                        $this->emailGrantId($item),
                        $at
                    ),
                };
                $handler = match ($item->getPurpose()) {
                    'activation' => new ExpireInvitationDeliveryHandler(
                        $fixture->activation,
                        $fixture->unitOfWork,
                        $fixture->events
                    ),
                    'password_reset' => new ExpirePasswordResetDeliveryHandler(
                        $fixture->reset,
                        $fixture->unitOfWork,
                        $fixture->events
                    ),
                    default => new ExpireEmailChangeHandler(
                        $fixture->users,
                        $fixture->email,
                        $fixture->unitOfWork,
                        $fixture->events
                    ),
                };
                $handler->handle(CommandMessage::create($command));
            }

            self::assertCount(($index + 1) * 2, $fixture->events->events());
        }

        self::assertSame([], $fixture->discovery()->handle($query));
        self::assertSame([], $fixture->activation->findDue($at, 100));
        self::assertSame([], $fixture->reset->findDue($at, 100));
        self::assertSame([], $fixture->email->findDue($at, 100));
        foreach ([$fixture->activation, $fixture->email, $fixture->reset] as $repository) {
            foreach ($repository->all() as $grant) {
                self::assertFalse($grant->getDelivery()->hasRecoverableMaterial());
            }
        }
    }

    /** @return list<mixed> */
    private static function userState(User $user): array
    {
        return [
            $user->getId()->toString(), $user->getEmail()->canonical(), $user->getState(),
            $user->getPasswordHash()?->toString(), $user->getAuthenticationVersion(),
            $user->getAuthenticationAuthorityRevision(), $user->getAuthorizationAssignmentRevision(),
            array_map(static fn (RoleId $id): string => $id->toString(), $user->getRoleIds()),
            $user->getCanonicalEmailRevision(), $user->getPendingEmailChange()?->canonical(),
            $user->getEmailChangeReservationRevision(), $user->getCreatedAt()->format('Y-m-d\\TH:i:s.uP'),
            $user->getUpdatedAt()->format('Y-m-d\\TH:i:s.uP')
        ];
    }

    private function emailGrantId(ExpiredCredentialDelivery $item): EmailChangeGrantId
    {
        $id = $item->getEmailChangeGrantId();
        self::assertNotNull($id);

        return $id;
    }

    private function orderedFixture(): ExpiredCredentialFixture
    {
        $fixture = new ExpiredCredentialFixture('activation');
        self::assertTrue($fixture->activation->replace(
            $fixture->initial,
            $fixture->initial->revoke($fixture->issuedAt)
        ));
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            // More than one page of earlier terminal history precedes all eligible rows.
            for ($index = 0; $index < 61; ++$index) {
                $grant = $this->grant(
                    $fixture,
                    $purpose,
                    'history-'.$index,
                    null,
                    $fixture->expiresAt->modify('-1 minute')
                );
                self::assertTrue($this->add($fixture, $grant));
                self::assertTrue($fixture->replace($grant, $grant->revoke($fixture->issuedAt)));
            }

            // Insertion conflicts with both expiry and ID order; UUIDs are intentionally not generated.
            foreach (
                [
                ['44444444-4444-4444-8444-444444444444', '+2 minutes'],
                ['22222222-2222-4222-8222-222222222222', '+1 minute'],
                ['33333333-3333-4333-8333-333333333333', '+0 seconds'],
                ['11111111-1111-4111-8111-111111111111', '+1 minute']
                ] as [$id, $offset]
            ) {
                $grant = $this->grant($fixture, $purpose, $id, $id, $fixture->expiresAt->modify($offset));
                self::assertTrue($this->add($fixture, $grant));
            }
        }

        return $fixture;
    }

    private function add(
        ExpiredCredentialFixture $fixture,
        ActivationGrant|PasswordResetGrant|EmailChangeGrant $grant
    ): bool {
        return match (true) {
            $grant instanceof ActivationGrant => $fixture->activation->add($grant),
            $grant instanceof PasswordResetGrant => $fixture->reset->add($grant),
            default => $fixture->email->add($grant),
        };
    }

    private function grant(
        ExpiredCredentialFixture $fixture,
        string $purpose,
        string $label,
        ?string $id,
        DateTimeImmutable $expiresAt
    ): ActivationGrant|PasswordResetGrant|EmailChangeGrant {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString($purpose.'-'.$label.'@example.test'),
            $fixture->issuedAt
        );
        $passwordHash = $fixture->user->getPasswordHash();
        self::assertNotNull($passwordHash);
        $user->activate($passwordHash, $fixture->issuedAt);
        $email = EmailAddress::fromString('next-'.$purpose.'-'.$label.'@example.test');
        if ($purpose === 'email_change') {
            $user->requestEmailChange($email, $fixture->issuedAt);
        }

        $fixture->users->add($user);
        if ($purpose === 'email_change') {
            $grant = EmailChangeGrant::issue(
                $user->getId(),
                EmailChangeCredential::fromString($label),
                $fixture->issuedAt,
                $expiresAt,
                $email,
                'fixture:encrypted',
                1
            );
            if ($id !== null) {
                return FabricatedEmailChangeGrant::withIdentifiers(
                    $grant,
                    EmailChangeGrantId::generate(),
                    EmailChangeDeliveryId::fromString($id)
                );
            }

            return $grant;
        }

        if ($purpose === 'activation') {
            if ($id !== null) {
                return ExtensibleActivationGrant::reconstitute(
                    ActivationGrantId::generate(),
                    $user->getId(),
                    hash('sha256', $label),
                    $expiresAt,
                    ActivationDelivery::create(
                        ActivationDeliveryId::fromString($id),
                        $user->getId(),
                        $email,
                        'fixture:encrypted',
                        $expiresAt,
                        $fixture->issuedAt
                    )
                );
            }

            return ActivationGrant::issue(
                $user->getId(),
                ActivationCredential::fromString($label),
                $fixture->issuedAt,
                $expiresAt,
                $email,
                'fixture:encrypted'
            );
        }

        if ($id !== null) {
            return ExtensiblePasswordResetGrant::reconstitute(
                PasswordResetGrantId::generate(),
                $user->getId(),
                hash('sha256', $label),
                $expiresAt,
                PasswordResetDelivery::create(
                    PasswordResetDeliveryId::fromString($id),
                    $user->getId(),
                    $email,
                    'fixture:encrypted',
                    $expiresAt,
                    $fixture->issuedAt
                )
            );
        }

        return PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString($label),
            $fixture->issuedAt,
            $expiresAt,
            $email,
            'fixture:encrypted'
        );
    }
}
