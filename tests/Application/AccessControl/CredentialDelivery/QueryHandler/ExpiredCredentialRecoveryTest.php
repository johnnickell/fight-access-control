<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ExpireInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ExpireInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\FabricatedEmailChangeGrant;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service as EmailChangeService;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ExpireInvitationDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ExpireEmailChangeHandler::class)]
#[CoversClass(FindExpiredCredentialDeliveriesHandler::class)]
#[CoversClass(FindExpiredCredentialDeliveries::class)]
#[CoversClass(ExpiredCredentialDelivery::class)]
#[CoversClass(ExpireInvitationDelivery::class)]
#[CoversClass(InvitationDeliveryExpired::class)]
#[CoversClass(ExpirePasswordResetDelivery::class)]
#[CoversClass(PasswordResetDeliveryExpired::class)]
#[CoversClass(ExpireEmailChange::class)]
#[CoversClass(EmailChangeExpired::class)]
#[CoversClass(CredentialDelivery::class)]
#[CoversClass(CredentialDeliveryTimestamp::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(User::class)]
final class ExpiredCredentialRecoveryTest extends TestCase
{
    #[DataProvider('recoverableCases')]
    public function test_recovery_preserves_history_and_destroys_material_only_at_expiry(
        string $purpose,
        string $state,
        string $offset
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $before = $fixture->stage($state);
        $at = $fixture->expiresAt->modify($offset);
        $query = new FindExpiredCredentialDeliveries($at, 50);
        $work = $fixture->discovery()->handle(QueryMessage::create($query));
        self::assertSame($before, $fixture->current());
        self::assertSame(0, $fixture->unitOfWork->transactions);
        self::assertSame([], $fixture->events->events());
        if ($offset === '-1 microsecond') {
            self::assertSame([], $work);
            $fixture->handler()->handle(CommandMessage::create($fixture->command($at)));
            self::assertSame($before, $fixture->current());
            self::assertSame([], $fixture->events->events());

            return;
        }

        self::assertCount(1, $work);
        self::assertEquals($query, FindExpiredCredentialDeliveries::fromArray($query->toArray()));
        $item = $work[0];
        self::assertSame($purpose, $item->getPurpose());
        self::assertTrue($before->getDelivery()->getId()->equals($item->getDeliveryId()));
        self::assertTrue($before->getUserId()->equals($item->getUserId()));
        self::assertEquals($fixture->expiresAt, $item->getExpiresAt());
        self::assertSame($before->getRevision(), $item->getRevision());
        self::assertSame($before->getDelivery()->getStatus(), $item->getStatus());
        self::assertEquals($item, ExpiredCredentialDelivery::fromArray($item->toArray()));
        self::assertSame(
            ['purpose', 'delivery_id', 'user_id', 'email_change_grant_id', 'expires_at', 'revision', 'status'],
            array_keys($item->toArray())
        );
        self::assertEquals($purpose === 'email_change' ? $before->getId() : null, $item->getEmailChangeGrantId());
        $events = new InMemoryEventDispatcher(static function () use ($fixture): void {
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertFalse($fixture->current()->getDelivery()->hasRecoverableMaterial());
        });
        $command = $fixture->command($at);
        self::assertEquals($command, $command::fromArray($command->toArray()));
        $fixture->handler(events: $events)->handle(CommandMessage::create($command));
        $after = $fixture->current();
        self::assertSame($before->getId(), $after->getId());
        self::assertSame($before->getDelivery()->getId(), $after->getDelivery()->getId());
        self::assertSame(
            $purpose === 'email_change' ? CredentialDeliveryStatus::INVALIDATED : CredentialDeliveryStatus::EXPIRED,
            $after->getDelivery()->getStatus()
        );
        self::assertFalse($after->getDelivery()->hasRecoverableMaterial());
        self::assertNull($after->getDelivery()->getClaimToken());
        self::assertNull($after->getDelivery()->getClaimedAt());
        self::assertNull($after->getDelivery()->getLeaseUntil());
        self::assertSame($before->getDelivery()->getAttemptCount(), $after->getDelivery()->getAttemptCount());
        self::assertEquals($before->getDelivery()->getLastAttemptAt(), $after->getDelivery()->getLastAttemptAt());
        self::assertEquals($before->getDelivery()->getLastOutcomeAt(), $after->getDelivery()->getLastOutcomeAt());
        self::assertSame($before->getDelivery()->getLastFailure(), $after->getDelivery()->getLastFailure());
        self::assertCount(1, $events->events());
        $event = $events->events()[0];
        self::assertEquals($event, $event::fromArray($event->toArray()));
        $fixture->handler(events: $events)->handle(CommandMessage::create($command));
        self::assertSame($after, $fixture->current());
        self::assertCount(1, $events->events());
        self::assertSame([], $fixture->discovery()->handle(QueryMessage::create($query)));
    }

    #[DataProvider('terminalCases')]
    public function test_terminal_delivery_is_distinct_from_terminal_email_authority(
        string $purpose,
        string $state,
        bool $eligible
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $before = $fixture->stage($state);
        $work = $this->expired($fixture);
        self::assertCount($eligible ? 1 : 0, $work);
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        $after = $fixture->current();
        self::assertTrue($before->getDelivery()->sameStateAs($after->getDelivery()));
        if ($eligible) {
            self::assertInstanceOf(EmailChangeGrant::class, $after);
            self::assertTrue($after->isExpired());
            self::assertNull($fixture->users->getById($fixture->user->getId())?->getPendingEmailChange());
            self::assertCount(1, $fixture->events->events());
        } else {
            self::assertSame($before, $after);
            self::assertSame([], $fixture->events->events());
        }
    }

    #[DataProvider('accountStates')]
    public function test_full_expiry_preserves_every_reachable_account_state(string $state): void
    {
        $fixture = new ExpiredCredentialFixture('email_change');
        $before = clone $fixture->user;
        if (in_array($state, ['disabled', 'enabled'], true)) {
            $before->disable($fixture->issuedAt);
            self::assertTrue($fixture->users->replaceLifecycleState($fixture->user, $before));
        }

        if ($state === 'enabled') {
            $enabled = clone $before;
            $enabled->enable($fixture->issuedAt);
            self::assertTrue($fixture->users->replaceLifecycleState($before, $enabled));
            $before = $enabled;
        }

        if (in_array($state, ['deleted', 'restored-active', 'restored-pending'], true)) {
            $before->delete($fixture->issuedAt);
            self::assertTrue($fixture->users->replaceLifecycleState($fixture->user, $before));
        }

        if (str_starts_with($state, 'restored')) {
            $restored = clone $before;
            $restored->restore(
                $state === 'restored-active' ? UserState::ACTIVE : UserState::PENDING_ACTIVATION,
                $fixture->issuedAt
            );
            self::assertTrue($fixture->users->replaceLifecycleState($before, $restored));
            $before = $restored;
        }

        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        $after = $fixture->users->getById($fixture->user->getId());
        self::assertInstanceOf(User::class, $after);
        self::assertSame($before->getState(), $after->getState());
        self::assertSame($before->getEmail()->canonical(), $after->getEmail()->canonical());
        self::assertEquals($before->getPasswordHash(), $after->getPasswordHash());
        self::assertSame($before->getAuthenticationVersion(), $after->getAuthenticationVersion());
        self::assertSame($before->getAuthenticationAuthorityRevision(), $after->getAuthenticationAuthorityRevision());
        self::assertSame($before->getAuthorizationAssignmentRevision(), $after->getAuthorizationAssignmentRevision());
        self::assertEquals($before->getRoleIds(), $after->getRoleIds());
        self::assertSame($before->getCanonicalEmailRevision(), $after->getCanonicalEmailRevision());
        self::assertEquals($before->getCreatedAt(), $after->getCreatedAt());
        self::assertEquals($fixture->expiresAt, $after->getUpdatedAt());
        self::assertNull($after->getPendingEmailChange());
        self::assertSame($before->getEmailChangeReservationRevision() + 1, $after->getEmailChangeReservationRevision());
        self::assertTrue($fixture->email->all()[0]->isExpired());
    }

    public function test_a_fresh_same_email_request_after_release_has_a_new_bound_generation(): void
    {
        $fixture = new ExpiredCredentialFixture('email_change');
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        $user = $fixture->users->getById($fixture->user->getId());
        self::assertInstanceOf(User::class, $user);
        $request = new RequestEmailChangeHandler(
            $fixture->users,
            $fixture->email,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($fixture->unitOfWork),
            $fixture->unitOfWork,
            new EmailChangeService\FixedEmailChangeCredentialGenerator('fresh-fixture'),
            new EmailChangeService\PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2030-01-01T01:01:00Z'),
            $fixture->events
        );
        $request->handle(CommandMessage::create(new RequestEmailChange(
            $user->getId(),
            $user->getId(),
            EmailAddress::fromString('next@example.test')
        )));

        $successor = $fixture->current();
        self::assertInstanceOf(EmailChangeGrant::class, $successor);
        self::assertFalse($successor->getId()->equals($fixture->initial->getId()));
        self::assertSame(3, $successor->getEmailChangeReservationRevision());
        $fixture->handler()->handle(CommandMessage::create($fixture->command($successor->getExpiresAt())));
        self::assertSame($successor, $fixture->current());
        self::assertSame(
            'next@example.test',
            $fixture->users->getById($user->getId())?->getPendingEmailChange()?->canonical()
        );
        self::assertCount(2, $fixture->events->events());
    }

    public function test_current_issued_authority_with_a_missing_or_ABA_reservation_fails_closed(): void
    {
        foreach (['missing', 'different', 'same-email-ABA'] as $case) {
            $fixture = new ExpiredCredentialFixture('email_change');
            $replacement = clone $fixture->user;
            $replacement->cancelEmailChange($fixture->issuedAt);
            self::assertTrue($fixture->users->replaceEmailChangeReservation($fixture->user, $replacement));
            if ($case !== 'missing') {
                $next = clone $replacement;
                $next->requestEmailChange(
                    EmailAddress::fromString($case === 'different' ? 'other@example.test' : 'next@example.test'),
                    $fixture->issuedAt
                );
                self::assertTrue($fixture->users->replaceEmailChangeReservation($replacement, $next));
                $replacement = $next;
            }

            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('Inconsistent issued authority was silently terminalized.');
            } catch (LogicException $failure) {
                self::assertSame(
                    'The expired email-change authority has no matching reservation.',
                    $failure->getMessage()
                );
                self::assertSame($fixture->initial, $fixture->current());
                self::assertSame($replacement, $fixture->users->getById($fixture->user->getId()));
                self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
            }
        }
    }

    public function test_missing_user_for_current_expired_authority_is_an_unresolved_failure_not_success(): void
    {
        $fixture = new ExpiredCredentialFixture('email_change');
        $users = $this->createStub(UserRepository::class);
        $handler = new ExpireEmailChangeHandler($users, $fixture->email, $fixture->unitOfWork, $fixture->events);
        $this->expectException(LogicException::class);
        try {
            $handler->handle(CommandMessage::create($fixture->command()));
        } finally {
            self::assertSame($fixture->initial, $fixture->current());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
        }
    }

    public function test_corrupt_email_relationships_are_rejected_without_implicit_repair(): void
    {
        $fixture = new ExpiredCredentialFixture('email_change');
        $grant = $fixture->email->all()[0];
        $invalid = [
            FabricatedEmailChangeGrant::withDeliveryOwner($grant, UserId::generate()),
            FabricatedEmailChangeGrant::withDeliveryExpiry($grant, $fixture->expiresAt->modify('+1 hour')),
            FabricatedEmailChangeGrant::withReservationRevision($grant, 0)
        ];
        foreach ($invalid as $corrupt) {
            self::assertFalse(new InMemoryEmailChangeGrantRepository()->add($corrupt));
            $repository = $this->createStub(EmailChangeGrantRepository::class);
            $repository->method('getLatestByUserId')->willReturn($corrupt);
            $events = new InMemoryEventDispatcher();
            try {
                new ExpireEmailChangeHandler($fixture->users, $repository, $fixture->unitOfWork, $events)->handle(
                    CommandMessage::create($fixture->command())
                );
                self::fail('Corrupt authority was repaired implicitly.');
            } catch (LogicException) {
                self::assertSame($grant, $fixture->current());
                self::assertSame($fixture->user, $fixture->users->getById($fixture->user->getId()));
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }

        $fabricated = FabricatedEmailChangeGrant::withReservationRevision($grant, 3);
        self::assertFalse($fixture->email->replace($fabricated, $grant->expireAt($fixture->expiresAt)));
        self::assertSame($grant, $fixture->current());
    }

    public function test_wrong_User_or_grant_lookup_ownership_cannot_release_an_unrelated_reservation(): void
    {
        $fixture = new ExpiredCredentialFixture('email_change');
        $foreign = new ExpiredCredentialFixture('email_change');
        foreach ([true, false] as $wrongUser) {
            $users = $this->createStub(UserRepository::class);
            $users->method('getById')->willReturn($foreign->user);
            $repository = $this->createStub(EmailChangeGrantRepository::class);
            $repository->method('getLatestByUserId')->willReturn($fixture->initial);
            $command = $fixture->command();
            if (!$wrongUser) {
                $command = new ExpireEmailChange(
                    'worker:expiry',
                    $foreign->user->getId(),
                    $fixture->email->all()[0]->getId(),
                    $fixture->expiresAt
                );
            }

            $events = new InMemoryEventDispatcher();
            try {
                new ExpireEmailChangeHandler($users, $repository, $fixture->unitOfWork, $events)->handle(
                    CommandMessage::create($command)
                );
                self::fail('Unrelated reservation was expired.');
            } catch (LogicException) {
                self::assertSame($fixture->initial, $fixture->current());
                self::assertSame($foreign->initial, $foreign->current());
                self::assertNotNull($foreign->user->getPendingEmailChange());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    #[DataProvider('families')]
    public function test_failed_commit_rolls_back_and_restart_rediscovers_the_original_identity(string $purpose): void
    {
        $fixture = new ExpiredCredentialFixture($purpose);
        $before = $fixture->stage('reclaimed');
        $expectedUser = clone $fixture->user;
        $fixture->unitOfWork->failNextCommit = true;
        try {
            $fixture->handler()->handle(CommandMessage::create($fixture->command()));
            self::fail('Failed commit was accepted.');
        } catch (RuntimeException) {
            self::assertSame($before, $fixture->current());
            self::assertUserStateEquals($expectedUser, $fixture->users->getById($fixture->user->getId()));
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
            self::assertCount(1, $this->expired($fixture, limit: 1));
        }

        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        self::assertFalse($fixture->current()->getDelivery()->hasRecoverableMaterial());
        self::assertCount(2, $fixture->events->events());
    }

    #[DataProvider('uncertainCases')]
    public function test_uncertain_commit_resolves_from_persisted_state_without_duplicate_success(
        string $purpose,
        bool $committed
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $fault = new RuntimeException('Indeterminate persistence result.');
        $unitOfWork = $this->createStub(TransactionalUnitOfWork::class);
        $unitOfWork->method('commitTransactional')->willReturnCallback(static function (
            callable $operation
        ) use (
            $fixture,
            $committed,
            $fault
): never {
            if (!$committed) {
                $fixture->unitOfWork->failNextCommit = true;
                try {
                    $fixture->unitOfWork->commitTransactional($operation);
                } catch (RuntimeException) {
                    throw $fault;
                }
            }

            $fixture->unitOfWork->commitTransactional($operation);
            throw $fault;
        });
        try {
            $fixture->handler($unitOfWork)->handle(CommandMessage::create($fixture->command()));
            self::fail('An uncertain result was accepted.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
        }

        self::assertSame(!$committed, $fixture->current()->getDelivery()->hasRecoverableMaterial());
        if ($purpose === 'email_change') {
            self::assertSame(
                !$committed,
                $fixture->users->getById($fixture->user->getId())?->getPendingEmailChange() !== null
            );
        }

        $restartEvents = new InMemoryEventDispatcher();
        $fixture->handler(events: $restartEvents)->handle(CommandMessage::create($fixture->command()));
        self::assertCount($committed ? 0 : 1, $restartEvents->events());
        self::assertFalse($fixture->current()->getDelivery()->hasRecoverableMaterial());
    }

    #[DataProvider('families')]
    public function test_post_commit_notification_failure_cannot_undo_cleanup_or_republish_on_retry(
        string $purpose
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $fault = new RuntimeException('Injected notification loss.');
        $events = new InMemoryEventDispatcher(static function ($event) use ($fault): void {
            if (!$event instanceof CommandFailedEvent) {
                throw $fault;
            }
        });
        try {
            $fixture->handler(events: $events)->handle(CommandMessage::create($fixture->command()));
            self::fail('Publication fault must retain ordinary failure semantics.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
        }

        $after = $fixture->current();
        self::assertFalse($after->getDelivery()->hasRecoverableMaterial());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        self::assertSame($after, $fixture->current());
        self::assertSame([], $fixture->events->events());
    }

    #[DataProvider('families')]
    public function test_failure_messages_do_not_publish_arbitrary_storage_diagnostics(string $purpose): void
    {
        $fixture = new ExpiredCredentialFixture($purpose);
        $fault = new RuntimeException('Injected unsafe row diagnostic: fixture-material recipient@example.test.');
        $unitOfWork = $this->createStub(TransactionalUnitOfWork::class);
        $unitOfWork->method('commitTransactional')->willThrowException($fault);
        try {
            $fixture->handler($unitOfWork)->handle(CommandMessage::create($fixture->command()));
            self::fail('Storage failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
        }

        $event = $fixture->events->events()[0];
        self::assertInstanceOf(CommandFailedEvent::class, $event);
        self::assertSame('Credential expiry failed.', $event->getErrorMessage());
        $payload = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('fixture-material', $payload);
        self::assertStringNotContainsString('recipient@example.test', $payload);
    }

    #[DataProvider('uncertainCases')]
    public function test_failure_publication_cannot_replace_the_original_throwable(
        string $purpose,
        bool $committed
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $original = new RuntimeException('Original cleanup failure.');
        $secondary = new RuntimeException('Failure notification unavailable.');
        $events = new InMemoryEventDispatcher(static function ($event) use ($original, $secondary): never {
            if ($event instanceof CommandFailedEvent) {
                throw $secondary;
            }

            throw $original;
        });
        $unitOfWork = $fixture->unitOfWork;
        if (!$committed) {
            $unitOfWork = $this->createStub(TransactionalUnitOfWork::class);
            $unitOfWork->method('commitTransactional')->willThrowException($original);
        }

        try {
            $fixture->handler($unitOfWork, $events)->handle(CommandMessage::create($fixture->command()));
            self::fail('Original failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($original, $runtimeException);
        }

        self::assertSame(!$committed, $fixture->current()->getDelivery()->hasRecoverableMaterial());
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        self::assertCount($committed ? 0 : 1, $fixture->events->events());
    }

    #[DataProvider('raceCases')]
    public function test_complete_state_CAS_fences_competing_expiry_and_delivery_or_lifecycle_outcomes(
        string $purpose,
        string $outcome,
        bool $cleanupWins
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $snapshot = $fixture->stage('claimed');
        $token = $snapshot->getDelivery()->getClaimToken();
        self::assertNotNull($token);
        $cleanup = $snapshot->expireDeliveryAt($fixture->expiresAt);
        if ($snapshot instanceof EmailChangeGrant) {
            $cleanup = $snapshot->expireAt($fixture->expiresAt);
        }

        $at = $fixture->issuedAt->modify('+1 second');
        $competitor = match ($outcome) {
            'delivered' => $snapshot->confirmDelivery($token, $at),
            'retry' => $snapshot->failDelivery($token, $at, CredentialDeliveryFailure::RETRYABLE_PROVIDER),
            'permanent' => $snapshot->failDeliveryPermanently($token, $at),
            'consumed' => $snapshot->consume($at),
            'revoked' => $snapshot->revoke($at),
            'delivery-expired' => $snapshot->expireDeliveryAt($fixture->expiresAt),
            default => throw new LogicException('Unknown fixture outcome.'),
        };
        [$winner, $loser] = $cleanupWins ? [$cleanup, $competitor] : [$competitor, $cleanup];
        self::assertTrue($fixture->replace($snapshot, $winner));
        self::assertFalse($fixture->replace(clone $snapshot, $loser));
        self::assertSame($winner, $fixture->current());
        // This arbitration is modeled repository proof, not parallel database qualification.
    }

    #[DataProvider('families')]
    public function test_two_cleanup_workers_commit_only_one_fact_and_response_loss_is_repeat_safe(
        string $purpose
    ): void {
        foreach ([['worker:a', 'worker:b'], ['worker:b', 'worker:a']] as [$winner, $loser]) {
            $fixture = new ExpiredCredentialFixture($purpose);
            $first = $fixture->handler();
            $second = $fixture->handler();
            $first->handle(CommandMessage::create($fixture->command(actor: $winner)));
            $persisted = $fixture->current();
            // A caller can lose the confirmed response and recreate the handler after restart.
            $second->handle(CommandMessage::create($fixture->command(actor: $loser)));
            self::assertSame($persisted, $fixture->current());
            self::assertCount(1, $fixture->events->events());
            self::assertSame($winner, $fixture->events->events()[0]->toArray()['actor_id']);
        }
    }

    public function test_lifecycle_and_expiry_share_complete_User_CAS_in_both_winner_orders(): void
    {
        foreach (['disable', 'delete', 'restore-active', 'restore-pending'] as $transition) {
            foreach ([true, false] as $cleanupWins) {
                $fixture = new ExpiredCredentialFixture('email_change');
                $before = $fixture->user;
                if (str_starts_with($transition, 'restore')) {
                    $deleted = clone $before;
                    $deleted->delete($fixture->issuedAt);
                    self::assertTrue($fixture->users->replaceLifecycleState($before, $deleted));
                    $before = $deleted;
                }

                $lifecycle = clone $before;
                if ($transition === 'disable') {
                    $lifecycle->disable($fixture->issuedAt);
                } elseif ($transition === 'delete') {
                    $lifecycle->delete($fixture->issuedAt);
                } else {
                    $lifecycle->restore(
                        $transition === 'restore-active' ? UserState::ACTIVE : UserState::PENDING_ACTIVATION,
                        $fixture->issuedAt
                    );
                }

                $expired = clone $before;
                $expired->expireEmailChange($fixture->expiresAt);
                if ($cleanupWins) {
                    $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                    self::assertFalse($fixture->users->replaceLifecycleState(clone $before, $lifecycle));
                    self::assertUserStateEquals($expired, $fixture->users->getById($before->getId()));
                } else {
                    self::assertTrue($fixture->users->replaceLifecycleState($before, $lifecycle));
                    self::assertFalse($fixture->users->replaceEmailChangeReservation(clone $before, $expired));
                    $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                    $expected = clone $lifecycle;
                    $expected->expireEmailChange($fixture->expiresAt);
                    self::assertUserStateEquals($expected, $fixture->users->getById($before->getId()));
                }

                self::assertTrue($fixture->email->all()[0]->isExpired());
                self::assertCount(1, $fixture->events->events());
            }
        }
    }

    public function test_invitation_missing_wrong_owner_obsolete_and_lost_CAS_are_no_ops(): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        $fixture->handler()->handle(CommandMessage::create(new ExpireInvitationDelivery(
            'worker:expiry',
            UserId::generate(),
            $fixture->activation->all()[0]->getDelivery()->getId(),
            $fixture->expiresAt
        )));
        $fixture->handler()->handle(CommandMessage::create(new ExpireInvitationDelivery(
            'worker:expiry',
            $fixture->user->getId(),
            ActivationDeliveryId::generate(),
            $fixture->expiresAt
        )));
        foreach ([null, new ExpiredCredentialFixture('activation')->initial, $fixture->initial] as $latest) {
            $repository = $this->createStub(ActivationGrantRepository::class);
            $repository->method('getByDeliveryId')->willReturn($fixture->initial);
            $repository->method('getLatestByUserId')->willReturn($latest);
            $handler = new ExpireInvitationDeliveryHandler($repository, $fixture->unitOfWork, $fixture->events);
            $handler->handle(CommandMessage::create($fixture->command()));
        }

        self::assertSame($fixture->initial, $fixture->current());
        self::assertSame([], $fixture->events->events());
    }

    public function test_invitation_storage_failure_rethrows_the_original_throwable_with_failure_evidence(): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        $repository = $this->createStub(ActivationGrantRepository::class);
        $fault = new RuntimeException('Injected expiry storage failure.');
        $repository->method('getByDeliveryId')->willThrowException($fault);
        try {
            new ExpireInvitationDeliveryHandler($repository, $fixture->unitOfWork, $fixture->events)->handle(
                CommandMessage::create($fixture->command())
            );
            self::fail('Storage fault was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
            self::assertSame($fixture->initial, $fixture->current());
        }
    }

    public function test_misaddressed_delivery_lookup_fails_closed_without_a_write(): void
    {
        foreach (['activation', 'password_reset'] as $purpose) {
            $fixture = new ExpiredCredentialFixture($purpose);
            $foreign = new ExpiredCredentialFixture($purpose);
            if ($purpose === 'activation') {
                $repository = $this->createStub(ActivationGrantRepository::class);
                $repository->method('getByDeliveryId')->willReturn($foreign->initial);
                $repository->method('getLatestByUserId')->willReturn($foreign->initial);
                $handler = new ExpireInvitationDeliveryHandler($repository, $fixture->unitOfWork, $fixture->events);
                $command = new ExpireInvitationDelivery(
                    'worker:expiry',
                    $foreign->user->getId(),
                    $fixture->activation->all()[0]->getDelivery()->getId(),
                    $fixture->expiresAt
                );
            } else {
                $repository = $this->createStub(PasswordResetGrantRepository::class);
                $repository->method('getByDeliveryId')->willReturn($foreign->initial);
                $repository->method('getLatestByUserId')->willReturn($foreign->initial);
                $handler = new ExpirePasswordResetDeliveryHandler($repository, $fixture->unitOfWork, $fixture->events);
                $command = new ExpirePasswordResetDelivery(
                    'worker:expiry',
                    $foreign->user->getId(),
                    $fixture->reset->all()[0]->getDelivery()->getId(),
                    $fixture->expiresAt
                );
            }

            try {
                $handler->handle(CommandMessage::create($command));
                self::fail('A misaddressed lookup was accepted.');
            } catch (LogicException) {
                self::assertSame($fixture->initial, $fixture->current());
                self::assertSame($foreign->initial, $foreign->current());
                self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
            }
        }
    }

    public function test_replaced_invitation_discovery_and_stale_cleanup_cannot_retarget_a_successor(): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        $old = $fixture->activation->all()[0];
        $successor = ActivationGrant::issue(
            $old->getUserId(),
            ActivationCredential::fromString('successor'),
            $fixture->expiresAt,
            $fixture->expiresAt->modify('+1 hour'),
            EmailAddress::fromString('next@example.test'),
            'fixture:successor'
        );
        self::assertTrue($fixture->activation->replaceWithSuccessor(
            $old,
            $old->revoke($fixture->issuedAt),
            $successor
        ));
        self::assertSame([], $this->expired($fixture, limit: 1));
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        self::assertSame($successor, $fixture->current());
        self::assertTrue($successor->getDelivery()->hasRecoverableMaterial());
        self::assertSame([], $fixture->events->events());
    }

    public function test_expiry_payloads_reject_blank_relative_and_normalized_invalid_timestamps(): void
    {
        $fixture = new ExpiredCredentialFixture('email_change');
        $models = [new FindExpiredCredentialDeliveries($fixture->expiresAt, 50), $this->expired($fixture)[0]];
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            $family = new ExpiredCredentialFixture($purpose);
            $models[] = $family->command();
            $family->handler()->handle(CommandMessage::create($family->command()));
            $models[] = $family->events->events()[0];
        }

        foreach ($models as $model) {
            $field = match (true) {
                $model instanceof FindExpiredCredentialDeliveries => 'at',
                $model instanceof ExpiredCredentialDelivery => 'expires_at',
                default => 'occurred_at',
            };
            foreach (['', 'tomorrow', '2030-02-30T01:00:00+00:00'] as $invalid) {
                $data = $model->toArray();
                $data[$field] = $invalid;
                try {
                    $model::fromArray($data);
                    self::fail('An invalid timestamp silently supplied or normalized the boundary.');
                } catch (DomainException $domainException) {
                    self::assertNotSame('', $domainException->getMessage());
                }
            }
        }
    }

    public function test_query_bounds_payloads_and_identity_validation(): void
    {
        foreach ([0, -1, 101, PHP_INT_MAX] as $limit) {
            try {
                new FindExpiredCredentialDeliveries(new DateTimeImmutable(), $limit);
                self::fail('Invalid bounds accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (
            [
            [],
            ['at' => '2030-01-01Z'],
            ['at' => '', 'limit' => 1],
            ['at' => null, 'limit' => 1],
            ['at' => '2030-01-01Z', 'limit' => '1']
            ] as $data
        ) {
            try {
                FindExpiredCredentialDeliveries::fromArray($data);
                self::fail('Invalid query accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $fixture = new ExpiredCredentialFixture('activation');
        $item = $this->expired($fixture, limit: 100)[0];
        foreach (array_keys($item->toArray()) as $missing) {
            $data = $item->toArray();
            unset($data[$missing]);
            try {
                ExpiredCredentialDelivery::fromArray($data);
                self::fail('Missing work identity accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (
            [
            ['purpose', 'unsupported'],
            ['revision', -1],
            ['revision', '0'],
            ['expires_at', null],
            ['status', ''],
            ['email_change_grant_id', 1],
            ['email_change_grant_id', EmailChangeGrantId::generate()->toString()]
            ] as [$field, $invalid]
        ) {
            $data = $item->toArray();
            $data[$field] = $invalid;
            try {
                ExpiredCredentialDelivery::fromArray($data);
                self::fail('Invalid identity accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            new ExpiredCredentialDelivery(
                'unsupported',
                $item->getDeliveryId(),
                $item->getUserId(),
                null,
                $item->getExpiresAt(),
                0,
                $item->getStatus()
            );
            self::fail('Unsupported constructor purpose accepted.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(
            FindExpiredCredentialDeliveries::class,
            FindExpiredCredentialDeliveriesHandler::queryRegistration()
        );
        self::assertSame(ExpireInvitationDelivery::class, ExpireInvitationDeliveryHandler::commandRegistration());
        $command = new ExpireInvitationDelivery(
            'worker:expiry',
            $item->getUserId(),
            ActivationDeliveryId::fromString($item->getDeliveryId()->toString()),
            $item->getExpiresAt()
        );
        $event = new InvitationDeliveryExpired(
            $command->getActorId(),
            $command->getUserId(),
            $command->getActivationDeliveryId(),
            $command->getOccurredAt()
        );
        self::assertSame('worker:expiry', $event->getActorId());
        self::assertSame($item->getUserId(), $event->getUserId());
        self::assertSame($command->getActivationDeliveryId(), $event->getActivationDeliveryId());
        self::assertSame($item->getExpiresAt(), $event->getOccurredAt());
        foreach ([$command, $event] as $message) {
            foreach (array_keys($message->toArray()) as $missing) {
                $data = $message->toArray();
                unset($data[$missing]);
                try {
                    $message::fromArray($data);
                    self::fail('Missing message data accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    #[DataProvider('families')]
    public function test_family_discovery_rejects_invalid_bounds(string $purpose): void
    {
        $fixture = new ExpiredCredentialFixture($purpose);
        $repository = match ($purpose) {
            'activation' => $fixture->activation,
            'password_reset' => $fixture->reset,
            'email_change' => $fixture->email,
            default => throw new LogicException('Unknown fixture purpose.'),
        };
        foreach ([0, -1, 101] as $limit) {
            try {
                $repository->findExpired($fixture->expiresAt, $limit);
                self::fail('Unbounded repository discovery accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_global_order_uses_expiry_instant_then_id_then_purpose_with_bounded_family_reads(): void
    {
        $at = new DateTimeImmutable('2030-01-01T01:00:00Z');
        $user = UserId::generate();
        $id = '22222222-2222-4222-8222-222222222222';
        $activation = new ExpiredCredentialDelivery(
            'activation',
            ActivationDeliveryId::fromString($id),
            $user,
            null,
            $at,
            0,
            CredentialDeliveryStatus::PENDING
        );
        $reset = new ExpiredCredentialDelivery(
            'password_reset',
            PasswordResetDeliveryId::fromString($id),
            $user,
            null,
            new DateTimeImmutable('2030-01-01T02:00:00+01:00'),
            0,
            CredentialDeliveryStatus::PENDING
        );
        $email = new ExpiredCredentialDelivery(
            'email_change',
            EmailChangeDeliveryId::fromString($id),
            $user,
            EmailChangeGrantId::generate(),
            $at,
            0,
            CredentialDeliveryStatus::DELIVERED
        );
        $activationRepo = $this->createMock(ActivationGrantRepository::class);
        $resetRepo = $this->createMock(PasswordResetGrantRepository::class);
        $emailRepo = $this->createMock(EmailChangeGrantRepository::class);
        $activationRepo->expects(self::once())->method('findExpired')->with($at, 2)->willReturn([$activation]);
        $resetRepo->expects(self::once())->method('findExpired')->with($at, 2)->willReturn([$reset]);
        $emailRepo->expects(self::once())->method('findExpired')->with($at, 2)->willReturn([$email]);
        $actual = new FindExpiredCredentialDeliveriesHandler(
            $activationRepo,
            $resetRepo,
            $emailRepo
        )->handle(
            QueryMessage::create(new FindExpiredCredentialDeliveries($at, 2))
        );
        self::assertSame([$activation, $email], $actual);
    }

    public function test_excluded_history_does_not_starve_work_and_repeated_pages_drain(): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        for ($index = 0; $index < 61; ++$index) {
            $grant = ActivationGrant::issue(
                UserId::generate(),
                ActivationCredential::fromString('excluded-'.$index),
                $fixture->issuedAt,
                $fixture->expiresAt,
                EmailAddress::fromString('fixture@example.test'),
                'fixture:encrypted'
            );
            self::assertTrue($fixture->activation->add($grant));
            self::assertTrue($fixture->activation->replace($grant, $grant->revoke($fixture->issuedAt)));
        }

        $later = ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('later'),
            $fixture->issuedAt,
            $fixture->expiresAt->modify('+1 microsecond'),
            EmailAddress::fromString('fixture@example.test'),
            'fixture:encrypted'
        );
        self::assertTrue($fixture->activation->add($later));
        $query = QueryMessage::create(new FindExpiredCredentialDeliveries($fixture->expiresAt->modify('+1 second'), 1));
        self::assertSame(
            $fixture->initial->getDelivery()->getId()->toString(),
            $fixture->discovery()->handle($query)[0]->getDeliveryId()->toString()
        );
        self::assertEquals($fixture->discovery()->handle($query), $fixture->discovery()->handle($query));
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        $next = $fixture->discovery()->handle($query);
        self::assertCount(1, $next);
        self::assertSame($later->getDelivery()->getId()->toString(), $next[0]->getDeliveryId()->toString());
        $fixture->handler()->handle(CommandMessage::create(new ExpireInvitationDelivery(
            'worker:expiry',
            $later->getUserId(),
            $later->getDelivery()->getId(),
            $later->getExpiresAt()
        )));
        self::assertSame([], $fixture->discovery()->handle($query));
    }

    /**
     * Asserts public User state, excluding mutable collection-iterator bookkeeping
     */
    private static function assertUserStateEquals(User $expected, ?User $actual): void
    {
        self::assertInstanceOf(User::class, $actual);
        self::assertTrue($expected->getId()->equals($actual->getId()));
        self::assertSame($expected->getEmail()->toString(), $actual->getEmail()->toString());
        self::assertSame($expected->getState(), $actual->getState());
        self::assertSame($expected->getPasswordHash()?->toString(), $actual->getPasswordHash()?->toString());
        self::assertSame($expected->getAuthenticationVersion(), $actual->getAuthenticationVersion());
        self::assertSame(
            $expected->getAuthenticationAuthorityRevision(),
            $actual->getAuthenticationAuthorityRevision()
        );
        self::assertSame(
            $expected->getAuthorizationAssignmentRevision(),
            $actual->getAuthorizationAssignmentRevision()
        );
        $expectedRoles = array_map(static fn (RoleId $id): string => $id->toString(), $expected->getRoleIds());
        $actualRoles = array_map(static fn (RoleId $id): string => $id->toString(), $actual->getRoleIds());
        sort($expectedRoles);
        sort($actualRoles);
        self::assertSame($expectedRoles, $actualRoles);
        self::assertSame($expected->getCanonicalEmailRevision(), $actual->getCanonicalEmailRevision());
        self::assertSame(
            $expected->getEmailChangeReservationRevision(),
            $actual->getEmailChangeReservationRevision()
        );
        self::assertSame(
            $expected->getPendingEmailChange()?->toString(),
            $actual->getPendingEmailChange()?->toString()
        );
        self::assertEquals($expected->getCreatedAt(), $actual->getCreatedAt());
        self::assertEquals($expected->getUpdatedAt(), $actual->getUpdatedAt());
    }

    /** @return list<ExpiredCredentialDelivery> */
    private function expired(
        ExpiredCredentialFixture $fixture,
        ?DateTimeImmutable $at = null,
        int $limit = 50
    ): array {
        return $fixture->discovery()->handle(QueryMessage::create(new FindExpiredCredentialDeliveries(
            $at ?? $fixture->expiresAt,
            $limit
        )));
    }

    /** @return list<array{string}> */
    public static function families(): array
    {
        return [['activation'], ['password_reset'], ['email_change']];
    }

    /** @return list<array{string, string, string}> */
    public static function recoverableCases(): array
    {
        $cases = [];
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach (['pending', 'claimed', 'retry', 'reclaimed'] as $state) {
                foreach (['-1 microsecond', '+0 seconds', '+1 microsecond'] as $offset) {
                    $cases[] = [$purpose, $state, $offset];
                }
            }
        }

        return $cases;
    }

    /** @return list<array{string, string, bool}> */
    public static function terminalCases(): array
    {
        $cases = [];
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach (
                ['delivered', 'permanent', 'delivery_expired', 'backoff_expired', 'consumed', 'revoked'] as $state
            ) {
                $cases[] = [
                    $purpose,
                    $state,
                    $purpose === 'email_change' && !in_array($state, ['consumed', 'revoked'], true)
                ];
            }
        }

        $cases[] = ['email_change', 'authority_expired', false];

        return $cases;
    }

    /** @return list<array{string}> */
    public static function accountStates(): array
    {
        return [['active'], ['disabled'], ['enabled'], ['deleted'], ['restored-active'], ['restored-pending']];
    }

    /** @return list<array{string, bool}> */
    public static function uncertainCases(): array
    {
        $cases = [];
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach ([true, false] as $committed) {
                $cases[] = [$purpose, $committed];
            }
        }

        return $cases;
    }

    /** @return list<array{string, string, bool}> */
    public static function raceCases(): array
    {
        $cases = [];
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach (['delivered', 'retry', 'permanent', 'consumed', 'revoked', 'delivery-expired'] as $outcome) {
                foreach ([true, false] as $cleanupWins) {
                    $cases[] = [$purpose, $outcome, $cleanupWins];
                }
            }
        }

        return $cases;
    }
}
