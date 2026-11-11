<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CorrectPendingInvitationHandler::class)]
#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(EnableUserHandler::class)]
#[CoversClass(InvitePendingUserHandler::class)]
#[CoversClass(RecoverPendingRegistrationHandler::class)]
#[CoversClass(RegisterUserHandler::class)]
#[CoversClass(RestoreUserHandler::class)]
final class OptionalLifecycleAuditTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['register', 'invite', 'recover', 'correct', 'enable', 'disable', 'delete', 'restore'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function test_null_and_omitted_audit_preserve_the_complete_business_outcome(string $operation): void
    {
        foreach ([false, true] as $omitAudit) {
            $fixture = new LifecycleDispatchFixture($operation, auditEnabled: false, omitAudit: $omitAudit);
            $message = CommandMessage::arrayDeserialize(CommandMessage::create($fixture->command)->arraySerialize());
            $fixture->bus->dispatch($message);
            self::assertTrue($fixture->registration->unitOfWork->transactionCompleted);
            self::assertCount(1, $fixture->registration->userRepository->all());
            self::assertSame([], $fixture->audit->all());
            self::assertCount(1, $fixture->registration->eventDispatcher->events());
            self::assertNotInstanceOf(CommandFailedEvent::class, $fixture->registration->eventDispatcher->events()[0]);
            $user = $fixture->registration->userRepository->all()[0];
            self::assertSame(match ($operation) {
                'register', 'invite', 'recover', 'correct' => UserState::PENDING_ACTIVATION,
                'disable' => UserState::DISABLED,
                'delete' => UserState::DELETED,
                default => UserState::ACTIVE
            }, $user->getState());
            if (in_array($operation, ['register', 'invite', 'correct', 'recover'], true)) {
                $grant = $fixture->registration->activationGrantRepository->getLatestByUserId($user->getId());
                self::assertNotNull($grant);
                self::assertTrue($grant->isIssued());
                self::assertTrue($grant->getDelivery()->hasRecoverableMaterial());
                $expectedOrigin = ActivationOrigin::PRIVATE_INVITATION;
                if (in_array($operation, ['register', 'recover'], true)) {
                    $expectedOrigin = ActivationOrigin::REGISTRATION;
                }

                self::assertSame($expectedOrigin, $grant->getOrigin());
                self::assertCount($operation === 'register' ? 2 : 0, $user->getRoleIds());
            }
        }
    }

    #[DataProvider('operations')]
    public function test_enabled_evidence_matches_authoritative_state_time_and_message(string $operation): void
    {
        $fixture = new LifecycleDispatchFixture($operation);
        $before = $fixture->registration->userRepository->all()[0] ?? null;
        $grantBefore = $fixture->registration->activationGrantRepository->all()[0] ?? null;
        $message = CommandMessage::create($fixture->command);
        $fixture->bus->dispatch($message);
        self::assertCount(1, $fixture->audit->all());
        $evidence = $fixture->audit->all()[0];
        $user = $fixture->registration->userRepository->all()[0];
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        self::assertSame($user->getId(), $evidence->getSubject()->getId());
        self::assertSame('2030-01-08T00:00:00.000000+00:00', $evidence->toArray()['occurred_at']);
        self::assertSame($message->id()->toString(), $evidence->toArray()['correlation']['message_id']);
        $facts = $evidence->getFacts();
        self::assertInstanceOf(AuditUserOutcome::class, $facts);
        self::assertEquals($before === null ? null : AuditUserRevisions::fromUser($before), $facts->getBefore());
        self::assertEquals(AuditUserRevisions::fromUser($user), $facts->getAfter());
        self::assertEquals(
            $grantBefore === null ? null : AuditGrantState::fromGrant($grantBefore),
            $facts->getGrantBefore()
        );
        $grantAfter = $fixture->registration->activationGrantRepository->getLatestByUserId($user->getId());
        self::assertEquals(
            $grantAfter === null ? null : AuditGrantState::fromGrant($grantAfter),
            $facts->getGrantAfter()
        );
        if ($grantBefore !== null) {
            self::assertEquals(
                AuditGrantState::fromGrant($fixture->registration->activationGrantRepository->all()[0]),
                $facts->getReplacedPredecessor()
            );
        }

        if ($operation === 'register') {
            self::assertInstanceOf(AnonymousId::class, $evidence->actorId());
            self::assertNotEquals($user->getId()->toString(), $evidence->actorId()->toString());
        }

        $serialized = json_encode($evidence->toArray(), JSON_THROW_ON_ERROR);
        foreach (['@example.test', 'ciphertext:', 'lifecycle-credential', 'password', 'error_message'] as $excluded) {
            self::assertStringNotContainsString($excluded, $serialized);
        }
    }

    #[DataProvider('operations')]
    public function test_commit_loss_after_staged_evidence_rolls_back_both(string $operation): void
    {
        $fixture = new LifecycleDispatchFixture($operation);
        $before = array_map(AuditUserRevisions::fromUser(...), $fixture->registration->userRepository->all());
        $grantsBefore = $fixture->registration->activationGrantRepository->all();
        $fixture->registration->unitOfWork->failNextCommit = true;
        try {
            $fixture->bus->execute($fixture->command);
            self::fail('The losing transaction was accepted.');
        } catch (RuntimeException) {
            self::assertEquals($before, array_map(
                AuditUserRevisions::fromUser(...),
                $fixture->registration->userRepository->all()
            ));
            self::assertEquals($grantsBefore, $fixture->registration->activationGrantRepository->all());
            self::assertSame([], $fixture->audit->all());
        }
    }

    public function test_invitation_preserves_each_explicit_originating_kind_through_message_round_trip(): void
    {
        foreach ([UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate()] as $actor) {
            $fixture = new LifecycleDispatchFixture('invite');
            self::assertInstanceOf(InvitePendingUser::class, $fixture->command);
            $command = new InvitePendingUser($actor, $fixture->command->getUserId(), $fixture->command->getEmail());
            $message = CommandMessage::create($command);
            $fixture->bus->dispatch(CommandMessage::arrayDeserialize($message->arraySerialize()));
            self::assertEquals($actor, $fixture->audit->all()[0]->actorId());
            self::assertSame($actor::class, $fixture->audit->all()[0]->actorId()::class);
        }
    }

    public function test_pending_restoration_does_not_invent_a_grant_predecessor_or_duplicate_helper_record(): void
    {
        foreach ([false, true] as $auditEnabled) {
            $fixture = new LifecycleDispatchFixture('restore', auditEnabled: $auditEnabled);
            self::assertInstanceOf(RestoreUser::class, $fixture->command);
            $command = new RestoreUser(
                $fixture->command->getActorId(),
                $fixture->command->getUserId(),
                UserState::PENDING_ACTIVATION
            );
            $priorUser = $fixture->registration->userRepository->all()[0];
            $priorGrant = ActivationGrant::issue(
                ActivationOrigin::REGISTRATION,
                $priorUser->getId(),
                ActivationCredential::fromString('old-restoration-credential'),
                new DateTimeImmutable('2030-01-01T00:00:00Z'),
                new DateTimeImmutable('2030-01-08T00:00:00Z'),
                $priorUser->getEmail(),
                'ciphertext:old-restoration-credential'
            );
            self::assertTrue($fixture->registration->activationGrantRepository->add($priorGrant));
            $terminal = $priorGrant->revoke(new DateTimeImmutable('2030-01-02T00:00:00Z'));
            self::assertTrue($fixture->registration->activationGrantRepository->replace($priorGrant, $terminal));
            $fixture->bus->execute($command);
            $user = $fixture->registration->userRepository->getById($command->getUserId());
            self::assertInstanceOf(User::class, $user);
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertCount(2, $fixture->registration->activationGrantRepository->all());
            self::assertSame($terminal, $fixture->registration->activationGrantRepository->all()[0]);
            self::assertCount($auditEnabled ? 1 : 0, $fixture->audit->all());
            if ($auditEnabled) {
                $facts = $fixture->audit->all()[0]->getFacts();
                self::assertInstanceOf(AuditUserOutcome::class, $facts);
                self::assertNull($facts->getGrantBefore());
                self::assertNull($facts->getReplacedPredecessor());
                self::assertEquals(
                    AuditGrantState::fromGrant($fixture->registration->activationGrantRepository->all()[1]),
                    $facts->getGrantAfter()
                );
            }
        }
    }

    public function test_disabling_audit_does_not_bypass_pending_target_authorization(): void
    {
        foreach (['correct', 'recover'] as $operation) {
            foreach ([false, true] as $auditEnabled) {
                $fixture = new LifecycleDispatchFixture($operation, auditEnabled: $auditEnabled, authorized: false);
                $before = $fixture->registration->userRepository->all();
                $grantsBefore = $fixture->registration->activationGrantRepository->all();
                try {
                    $fixture->bus->execute($fixture->command);
                    self::fail('Audit composition bypassed target authorization.');
                } catch (PermissionDenied) {
                    self::assertSame($before, $fixture->registration->userRepository->all());
                    self::assertSame($grantsBefore, $fixture->registration->activationGrantRepository->all());
                    self::assertSame([], $fixture->audit->all());
                }
            }
        }
    }

    public function test_disabled_registration_still_requires_authoritative_default_roles(): void
    {
        $fixture = new LifecycleDispatchFixture('register', auditEnabled: false);
        $fixture->registration->roleState->roles = [];
        $this->expectException(LogicException::class);
        try {
            $fixture->bus->execute($fixture->command);
        } finally {
            self::assertSame([], $fixture->registration->userRepository->all());
            self::assertSame([], $fixture->registration->activationGrantRepository->all());
            self::assertSame([], $fixture->audit->all());
        }
    }

    #[DataProvider('operations')]
    public function test_audit_failure_preserves_the_exact_primary_fault(string $operation): void
    {
        $fixture = new LifecycleDispatchFixture($operation, failAudit: true);
        try {
            $fixture->bus->execute($fixture->command);
            self::fail('Audit storage failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fixture->audit->failure(), $runtimeException);
            self::assertSame([], $fixture->audit->all());
            self::assertFalse($fixture->registration->unitOfWork->transactionCompleted);
        }
    }

    #[DataProvider('operations')]
    public function test_rejected_repeat_does_not_create_another_committed_record(string $operation): void
    {
        $fixture = new LifecycleDispatchFixture($operation);
        $fixture->bus->execute($fixture->command);
        try {
            $fixture->bus->execute($fixture->command);
            self::fail('The repeated lifecycle intent was accepted.');
        } catch (StateConflict | PreconditionFailed) {
            self::assertCount(1, $fixture->audit->all());
        }
    }
}
