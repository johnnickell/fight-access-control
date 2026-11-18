<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use DateTimeImmutable;
use DateTimeZone;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindCredentialDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindInvitationDeliveryStatusHandler::class)]
#[CoversClass(FindCredentialDeliveryStatusHandler::class)]
#[CoversClass(FindDueCredentialDeliveriesHandler::class)]
#[CoversClass(FindExpiredCredentialDeliveriesHandler::class)]
#[CoversClass(InvitationDeliveryStatusView::class)]
#[CoversClass(CredentialDeliveryAbsent::class)]
final class CredentialQueryContractTest extends TestCase
{
    public function test_invitation_status_rejects_a_repository_returning_another_user(): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        $repository = $this->createStub(ActivationGrantRepository::class);
        $repository->method('getLatestByUserId')->willReturn($fixture->initial);
        $this->expectException(LogicException::class);
        new FindInvitationDeliveryStatusHandler($repository)->handle(
            QueryMessage::create(new FindInvitationDeliveryStatus(UserId::generate()))
        );
    }

    #[DataProvider('families')]
    public function test_exact_status_rejects_a_different_generation(string $purpose): void
    {
        $fixture = new ExpiredCredentialFixture($purpose);
        $activation = $this->createStub(ActivationGrantRepository::class);
        $reset = $this->createStub(PasswordResetGrantRepository::class);
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $repository = match ($purpose) {
            'activation' => $activation,
            'password_reset' => $reset,
            'email_change' => $email,
            default => throw new LogicException('Unknown family fixture.')
        };
        $repository->method('getByDeliveryId')->willReturn($fixture->initial);
        $this->expectException(LogicException::class);
        new FindCredentialDeliveryStatusHandler($activation, $reset, $email)->handle(QueryMessage::create(
            new FindCredentialDeliveryStatus($purpose, UserId::generate()->toString())
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            yield $purpose => [$purpose];
        }
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function invalidBatches(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach ([false, true] as $expired) {
                $defects = ['excessive', 'element', 'purpose', 'future', 'order', 'duplicate', 'user', 'keys'];
                foreach ($defects as $defect) {
                    yield $purpose.'-'.(int) $expired.'-'.$defect => [$purpose, $expired, $defect];
                }
            }
        }
    }

    #[DataProvider('invalidBatches')]
    public function test_each_source_rejects_invalid_work_before_global_selection(
        string $purpose,
        bool $expired,
        string $defect
    ): void {
        $at = new DateTimeImmutable('2030-01-01T00:00:00.123456Z');
        $first = $this->work($purpose, $expired, $at->modify('-1 second'), 1);
        $second = $this->work($purpose, $expired, $at, 2);
        $batch = match ($defect) {
            'excessive' => [$first, $second, $this->work($purpose, $expired, $at, 3)],
            'element' => ['not work'],
            'purpose' => [$this->work($purpose === 'activation' ? 'email_change' : 'activation', $expired, $at, 1)],
            'future' => [$this->work($purpose, $expired, $at->modify('+1 microsecond'), 1)],
            'order' => [$second, $first],
            'duplicate' => [$first, $first],
            'user' => [$first, $this->work($purpose, $expired, $at, 2, $first->getUserId())],
            'keys' => [1 => $first],
            default => throw new LogicException('Unknown batch fixture.')
        };
        $activation = $this->createStub(ActivationGrantRepository::class);
        $reset = $this->createStub(PasswordResetGrantRepository::class);
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $method = $expired ? 'findExpired' : 'findDue';
        $repositories = ['activation' => $activation, 'password_reset' => $reset, 'email_change' => $email];
        foreach ($repositories as $family => $repo) {
            $repo->method($method)->willReturn($family === $purpose ? $batch : []);
        }

        $handler = new FindDueCredentialDeliveriesHandler($activation, $reset, $email);
        if ($expired) {
            $handler = new FindExpiredCredentialDeliveriesHandler($activation, $reset, $email);
        }

        $query = $expired ? new FindExpiredCredentialDeliveries($at, 2) : new FindDueCredentialDeliveries($at, 2);
        $this->expectException(LogicException::class);
        $handler->handle(QueryMessage::create($query));
    }

    /** @return iterable<string, array{string, string}> */
    public static function statusFaults(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change', 'invitation'] as $purpose) {
            foreach (['purpose', 'owner', 'expiry'] as $fault) {
                yield $purpose.'-'.$fault => [$purpose, $fault];
            }
        }
    }

    #[DataProvider('statusFaults')]
    public function test_status_rejects_inconsistent_coupled_facts(string $purpose, string $fault): void
    {
        $fixture = new ExpiredCredentialFixture($purpose === 'invitation' ? 'activation' : $purpose);
        $grant = $this->createStub($fixture->initial::class);
        $grant->method('getDelivery')->willReturn($fixture->initial->getDelivery());
        $grant->method('getUserId')->willReturn($fault === 'owner' ? UserId::generate() : $fixture->user->getId());
        $grant->method('getExpiresAt')->willReturn(
            $fault === 'expiry' ? $fixture->expiresAt->modify('+1 microsecond') : $fixture->expiresAt
        );
        $grant->method('purpose')->willReturn($fault === 'purpose' ? 'wrong' : $fixture->initial->purpose());
        $activation = $this->createStub(ActivationGrantRepository::class);
        $reset = $this->createStub(PasswordResetGrantRepository::class);
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $repository = match ($purpose) {
            'activation', 'invitation' => $activation,
            'password_reset' => $reset,
            'email_change' => $email,
            default => throw new LogicException('Unknown family fixture.')
        };
        $repository->method($purpose === 'invitation' ? 'getLatestByUserId' : 'getByDeliveryId')->willReturn($grant);
        $this->expectException(LogicException::class);
        if ($purpose === 'invitation') {
            new FindInvitationDeliveryStatusHandler($activation)->handle(QueryMessage::create(
                new FindInvitationDeliveryStatus($grant->getUserId())
            ));
        } else {
            new FindCredentialDeliveryStatusHandler($activation, $reset, $email)->handle(QueryMessage::create(
                new FindCredentialDeliveryStatus($purpose, $fixture->initial->getDelivery()->getId()->toString())
            ));
        }
    }

    #[DataProvider('families')]
    public function test_terminal_history_is_present_without_side_effects_or_new_authority(string $purpose): void
    {
        $fixture = new ExpiredCredentialFixture($purpose);
        $terminal = $fixture->initial->revoke($fixture->issuedAt);
        self::assertTrue($fixture->replace($fixture->initial, $terminal));
        $transactions = $fixture->unitOfWork->transactions;
        $handler = new FindCredentialDeliveryStatusHandler(
            $fixture->activationGrantRepository,
            $fixture->passwordResetGrantRepository,
            $fixture->emailChangeGrantRepository
        );
        $query = QueryMessage::create(new FindCredentialDeliveryStatus(
            $purpose,
            $terminal->getDelivery()->getId()->toString()
        ));
        $status = $handler->handle($query);
        self::assertInstanceOf(CredentialDeliveryStatusView::class, $status);
        self::assertSame(CredentialDeliveryStatus::INVALIDATED, $status->getStatus());
        self::assertSame($terminal->getRevision(), $status->getRevision());
        self::assertEquals($status, $handler->handle($query));
        if ($purpose === 'activation') {
            $invitation = new FindInvitationDeliveryStatusHandler($fixture->activationGrantRepository)->handle(
                QueryMessage::create(new FindInvitationDeliveryStatus($terminal->getUserId()))
            );
            self::assertInstanceOf(InvitationDeliveryStatusView::class, $invitation);
            self::assertSame(CredentialDeliveryStatus::INVALIDATED, $invitation->getStatus());
            self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $invitation->getOrigin());
        }

        self::assertSame($terminal, $fixture->current());
        self::assertFalse($fixture->current()->isIssued());
        self::assertFalse($fixture->current()->getDelivery()->hasRecoverableMaterial());
        self::assertSame($transactions, $fixture->unitOfWork->transactions);
        self::assertSame([], $fixture->eventDispatcher->events());
    }

    #[DataProvider('families')]
    public function test_terminal_reset_and_invitation_cannot_be_reported_as_recoverable_expiry(string $purpose): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $work = $this->work($purpose, true, $at, 1);
        self::assertInstanceOf(ExpiredCredentialDelivery::class, $work);
        $terminal = new ExpiredCredentialDelivery(
            $purpose,
            $work->getDeliveryId(),
            $work->getUserId(),
            $work->getEmailChangeGrantId(),
            $at,
            1,
            CredentialDeliveryStatus::DELIVERED
        );
        $activation = $this->createStub(ActivationGrantRepository::class);
        $reset = $this->createStub(PasswordResetGrantRepository::class);
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $repositories = ['activation' => $activation, 'password_reset' => $reset, 'email_change' => $email];
        foreach ($repositories as $family => $repo) {
            $repo->method('findExpired')->willReturn($family === $purpose ? [$terminal] : []);
        }

        $handler = new FindExpiredCredentialDeliveriesHandler($activation, $reset, $email);
        $query = QueryMessage::create(new FindExpiredCredentialDeliveries($at, 1));
        if ($purpose === 'email_change') {
            self::assertSame([$terminal], $handler->handle($query));
        } else {
            $this->expectException(LogicException::class);
            $handler->handle($query);
        }
    }

    public function test_valid_full_source_batches_merge_before_global_top_n_with_instant_id_and_purpose_ties(): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00.123456Z');
        foreach ([false, true] as $expired) {
            $activation = $this->createMock(ActivationGrantRepository::class);
            $reset = $this->createMock(PasswordResetGrantRepository::class);
            $email = $this->createMock(EmailChangeGrantRepository::class);
            $first = $this->work('activation', $expired, $at, 1);
            $tied = $this->work('email_change', $expired, $at->setTimezone(new DateTimeZone('+02:00')), 1);
            $second = $this->work('password_reset', $expired, $at, 1);
            $method = $expired ? 'findExpired' : 'findDue';
            $activation->expects(self::once())->method($method)->with($at, 2)->willReturn([
                $first,
                $this->work('activation', $expired, $at, 2)
            ]);
            $reset->expects(self::once())->method($method)->with($at, 2)->willReturn([
                $second,
                $this->work('password_reset', $expired, $at, 2)
            ]);
            $email->expects(self::once())->method($method)->with($at, 2)->willReturn([
                $tied,
                $this->work('email_change', $expired, $at, 2)
            ]);
            $handler = new FindDueCredentialDeliveriesHandler($activation, $reset, $email);
            $query = new FindDueCredentialDeliveries($at, 2);
            if ($expired) {
                $handler = new FindExpiredCredentialDeliveriesHandler($activation, $reset, $email);
                $query = new FindExpiredCredentialDeliveries($at, 2);
            }

            self::assertSame([$first, $tied], $handler->handle(QueryMessage::create($query)));
        }
    }

    private function work(
        string $purpose,
        bool $expired,
        DateTimeImmutable $at,
        int $ordinal,
        ?UserId $userId = null
    ): DueCredentialDelivery|ExpiredCredentialDelivery {
        $userId ??= UserId::generate();
        $id = sprintf('22222222-2222-4222-8222-%012d', $ordinal);
        $deliveryId = match ($purpose) {
            'activation' => ActivationDeliveryId::fromString($id),
            'password_reset' => PasswordResetDeliveryId::fromString($id),
            'email_change' => EmailChangeDeliveryId::fromString($id),
            default => throw new LogicException('Unknown identity fixture.')
        };
        if ($expired) {
            return new ExpiredCredentialDelivery(
                $purpose,
                $deliveryId,
                $userId,
                $purpose === 'email_change' ? EmailChangeGrantId::generate() : null,
                $at,
                0,
                CredentialDeliveryStatus::PENDING
            );
        }

        return new DueCredentialDelivery($purpose, $deliveryId, $userId, $at, 0, CredentialDeliveryStatus::PENDING);
    }
}
