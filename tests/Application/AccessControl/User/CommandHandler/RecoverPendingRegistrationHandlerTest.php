<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RecoverPendingRegistrationHandler::class)]
#[CoversClass(RecoverPendingRegistration::class)]
#[CoversClass(PendingRegistrationRecovered::class)]
#[CoversClass(User::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
final class RecoverPendingRegistrationHandlerTest extends TestCase
{
    public function test_both_variants_commit_one_successor_and_exact_user_roles_before_publication(): void
    {
        foreach (['corrected@example.test', 'ALICE@example.test'] as $email) {
            $fixture = new RegistrationRecoveryFixture();
            $at = '2030-01-08T00:00:00Z';
            $command = $fixture->command($email);
            $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use ($fixture, $email): void {
                self::assertFalse($fixture->unitOfWork->transactionActive);
                self::assertTrue($fixture->unitOfWork->transactionCompleted);
                self::assertCount(1, $fixture->auditEvidenceRepository->all());
                self::assertCount(2, $fixture->activationGrantRepository->all());
                self::assertInstanceOf(PendingRegistrationRecovered::class, $event);
                self::assertSame(strtolower($email), $event->getEmail()->canonical());
            });
            $fixture->handler($at, $eventDispatcher)->handle(CommandMessage::create($command));
            $stored = $fixture->userRepository->getById($command->getUserId());
            self::assertNotNull($stored);
            self::assertSame($fixture->initialUser->getId(), $stored->getId());
            self::assertSame(UserState::PENDING_ACTIVATION, $stored->getState());
            self::assertEqualsCanonicalizing($fixture->initialUser->getRoleIds(), $stored->getRoleIds());
            self::assertSame(
                $fixture->initialUser->getAuthorizationAssignmentRevision(),
                $stored->getAuthorizationAssignmentRevision()
            );
            self::assertSame($fixture->initialUser->getAuthenticationVersion(), $stored->getAuthenticationVersion());
            self::assertSame(
                $fixture->initialUser->getAuthenticationAuthorityRevision(),
                $stored->getAuthenticationAuthorityRevision()
            );
            self::assertNull($stored->getPasswordHash());
            self::assertNull($stored->getPendingEmailChange());
            self::assertSame(
                $fixture->initialUser->getEmailChangeReservationRevision(),
                $stored->getEmailChangeReservationRevision()
            );
            self::assertEquals($fixture->initialUser->getCreatedAt(), $stored->getCreatedAt());
            if ($email === 'ALICE@example.test') {
                self::assertEquals($fixture->initialUser, $stored);
                self::assertSame('Alice@example.test', $stored->getEmail()->toString());
            } else {
                self::assertSame(1, $stored->getCanonicalEmailRevision());
                self::assertEquals(new DateTimeImmutable($at), $stored->getUpdatedAt());
                self::assertNull($fixture->userRepository->getByEmail($fixture->initialUser->getEmail()));
            }

            self::assertCount(1, $fixture->userRepository->all());
            $old = $fixture->activationGrantRepository->all()[0];
            self::assertTrue($old->isRevoked());
            self::assertNull($old->getDelivery()->getEncryptedMaterial());
            self::assertFalse($old->isUsableAt(new DateTimeImmutable($at)));
            $next = $fixture->current();
            self::assertFalse($old->getId()->equals($next->getId()));
            self::assertFalse($old->getDelivery()->getId()->equals($next->getDelivery()->getId()));
            self::assertSame(ActivationOrigin::REGISTRATION, $next->getOrigin());
            self::assertSame(0, $next->getRevision());
            self::assertTrue($next->matchesCredential(
                ActivationCredential::fromString('recovered-registration-credential')
            ));
            self::assertEquals(new DateTimeImmutable('2030-01-15T00:00:00Z'), $next->getExpiresAt());
            self::assertSame(strtolower($email), $next->getDelivery()->getEmail()->canonical());
            self::assertSame(
                'ciphertext:recovered-registration-credential',
                $next->getDelivery()->getEncryptedMaterial()?->reveal()
            );
            $audit = $fixture->auditEvidenceRepository->all()[0];
            self::assertSame(AuditAction::USER_PENDING_REGISTRATION_RECOVERED, $audit->action());
            self::assertSame($fixture->actorId, $audit->actorId());
            self::assertSame($command->getUserId(), $audit->getSubject()->getId());
            self::assertEquals(new DateTimeImmutable($at), $audit->getOccurredAt());
            $facts = $audit->getFacts();
            self::assertInstanceOf(AuditUserOutcome::class, $facts);
            self::assertEquals(AuditGrantState::fromGrant($fixture->initial), $facts->getGrantBefore());
            self::assertEquals(AuditGrantState::fromGrant($old), $facts->getReplacedPredecessor());
            self::assertEquals(AuditGrantState::fromGrant($next), $facts->getGrantAfter());
            $event = $eventDispatcher->events()[0];
            self::assertInstanceOf(PendingRegistrationRecovered::class, $event);
            self::assertSame($fixture->actorId, $event->getActorId());
            self::assertSame($command->getUserId(), $event->getUserId());
            self::assertSame($old->getDelivery()->getId(), $event->getPreviousActivationDeliveryId());
            self::assertSame($next->getDelivery()->getId(), $event->getActivationDeliveryId());
        }
    }

    public function test_current_command_and_event_round_trip_and_reject_malformed_or_extra_data(): void
    {
        $fixture = new RegistrationRecoveryFixture();
        $command = $fixture->command();
        self::assertSame(RecoverPendingRegistration::class, RecoverPendingRegistrationHandler::commandRegistration());
        self::assertEquals($command, RecoverPendingRegistration::fromArray($command->toArray()));
        self::assertSame($fixture->actorId, $command->getActorId());
        self::assertSame($fixture->initialUser->getId(), $command->getUserId());
        self::assertSame($fixture->initial->getDelivery()->getId(), $command->getExpectedActivationDeliveryId());
        self::assertSame('corrected@example.test', $command->getEmail()->canonical());
        $fixture->handler()->handle(CommandMessage::create($command));
        $event = $fixture->eventDispatcher->events()[0];
        self::assertInstanceOf(PendingRegistrationRecovered::class, $event);
        foreach ([$command, $event] as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));
            $data = $message->toArray();
            foreach (array_keys($data) as $key) {
                foreach (['missing', null, 123, 'invalid'] as $invalid) {
                    $bad = $data;
                    $bad[$key] = $invalid;
                    if ($invalid === 'missing') {
                        unset($bad[$key]);
                    }

                    try {
                        $message::fromArray($bad);
                        self::fail('Invalid required data was accepted.');
                    } catch (DomainException) {
                        self::addToAssertionCount(1);
                    }
                }
            }

            foreach (['credential', 'ciphertext', 'role_ids', 'origin', 'password'] as $extra) {
                try {
                    $message::fromArray($data + [$extra => 'untrusted']);
                    self::fail('Unexpected data was accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }

            self::assertStringNotContainsString('recovered-registration-credential', serialize($data));
        }
    }

    public function test_exact_actor_and_target_are_authorized_inside_transaction_before_protected_reads(): void
    {
        $fixture = new RegistrationRecoveryFixture();
        foreach (['actor', 'target'] as $wrong) {
            $command = new RecoverPendingRegistration(
                $wrong === 'actor' ? UserId::generate() : $fixture->actorId,
                $wrong === 'target' ? UserId::generate() : $fixture->initialUser->getId(),
                $fixture->initial->getDelivery()->getId(),
                EmailAddress::fromString('corrected@example.test')
            );
            try {
                $fixture->handler()->handle(CommandMessage::create($command));
                self::fail('Untrusted actor/target was admitted.');
            } catch (PermissionDenied) {
                $this->assertUnchanged($fixture);
            }
        }
    }

    public function test_absent_and_every_nonpending_user_and_missing_grant_reject_without_effects(): void
    {
        foreach (['absent', 'grant', UserState::ACTIVE, UserState::DISABLED, UserState::DELETED] as $condition) {
            $fixture = new RegistrationRecoveryFixture();
            if ($condition === 'grant') {
                $fixture->activationGrantRepository = new InMemoryActivationGrantRepository($fixture->unitOfWork);
            } elseif ($condition === 'absent') {
                $fixture->registration->userState->users = [];
            } else {
                $fixture->registration->userState->users = [UserFixture::withIdAndAuthenticationVersion(
                    $fixture->initialUser->getId(),
                    'Alice@example.test',
                    $condition,
                    1
                )];
            }

            $beforeUsers = $fixture->userRepository->all();
            $beforeGrants = $fixture->activationGrantRepository->all();
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('An ineligible User/grant was recovered.');
            } catch (ResourceNotFound | StateConflict $failure) {
                self::assertSame(
                    $condition === 'absent' ? ResourceNotFound::class : StateConflict::class,
                    $failure::class
                );
                self::assertEquals($beforeUsers, $fixture->userRepository->all());
                self::assertEquals($beforeGrants, $fixture->activationGrantRepository->all());
                self::assertSame([], $fixture->auditEvidenceRepository->all());
                self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
            }
        }
    }

    public function test_private_terminal_mismatched_and_stale_generations_reject(): void
    {
        foreach (['private', 'revoked', 'consumed', 'destination', 'generation', 'owner'] as $condition) {
            $fixture = new RegistrationRecoveryFixture();
            $grant = $fixture->initial;
            if (in_array($condition, ['private', 'destination', 'owner'], true)) {
                $grant = ActivationGrant::issue(
                    $condition === 'private' ? ActivationOrigin::PRIVATE_INVITATION : ActivationOrigin::REGISTRATION,
                    $condition === 'owner' ? UserId::generate() : $fixture->initialUser->getId(),
                    ActivationCredential::fromString('other-credential'),
                    new DateTimeImmutable('2030-01-01T00:00:00Z'),
                    new DateTimeImmutable('2030-01-08T00:00:00Z'),
                    EmailAddress::fromString(
                        $condition === 'destination' ? 'wrong@example.test' : 'Alice@example.test'
                    ),
                    'ciphertext:other-credential'
                );
                $fixture->activationGrantRepository = new InMemoryActivationGrantRepository($fixture->unitOfWork);
                self::assertTrue($fixture->activationGrantRepository->add($grant));
            } elseif ($condition === 'revoked' || $condition === 'consumed') {
                $at = new DateTimeImmutable('2030-01-01T01:00:00Z');
                $terminal = $grant->consume($at);
                if ($condition === 'revoked') {
                    $terminal = $grant->revoke($at);
                }

                self::assertTrue($fixture->activationGrantRepository->replace($grant, $terminal));
            }

            $before = $fixture->activationGrantRepository->all();
            try {
                $expected = $grant->getDelivery()->getId();
                if ($condition === 'generation') {
                    $expected = ActivationDeliveryId::generate();
                }

                $fixture->handler()->handle(CommandMessage::create($fixture->command(expected: $expected)));
                self::fail('Invalid authority was recovered.');
            } catch (PreconditionFailed | StateConflict $failure) {
                self::assertSame(
                    $condition === 'generation' ? PreconditionFailed::class : StateConflict::class,
                    $failure::class
                );
                self::assertEquals($before, $fixture->activationGrantRepository->all());
                self::assertEquals([$fixture->initialUser], $fixture->userRepository->all());
                self::assertSame([], $fixture->auditEvidenceRepository->all());
            }
        }
    }

    public function test_same_email_is_rejected_before_expiry_but_accepted_at_and_after_expiry(): void
    {
        foreach (['2030-01-07T23:59:59.999999Z', '2030-01-08T00:00:00Z', '2030-01-09T00:00:00Z'] as $at) {
            $fixture = new RegistrationRecoveryFixture();
            try {
                $fixture->handler($at)->handle(CommandMessage::create($fixture->command('ALICE@example.test')));
                self::assertNotSame('2030-01-07T23:59:59.999999Z', $at);
                self::assertEquals($fixture->initialUser, $fixture->userRepository->all()[0]);
                self::assertCount(2, $fixture->activationGrantRepository->all());
                self::assertCount(1, $fixture->auditEvidenceRepository->all());
            } catch (StateConflict) {
                self::assertSame('2030-01-07T23:59:59.999999Z', $at);
                $this->assertUnchanged($fixture);
            }
        }
    }

    public function test_canonical_claims_in_every_state_and_live_reservations_reject_atomically(): void
    {
        foreach ([...UserState::cases(), 'reservation'] as $claim) {
            $fixture = new RegistrationRecoveryFixture();
            $other = UserFixture::withState(
                'taken@example.test',
                $claim instanceof UserState ? $claim : UserState::ACTIVE
            );
            $fixture->userRepository->add($other);
            $destination = 'TAKEN@example.test';
            if ($claim === 'reservation') {
                $next = clone $other;
                $next->requestEmailChange(
                    EmailAddress::fromString('reserved@example.test'),
                    new DateTimeImmutable('2030-01-01Z')
                );
                self::assertTrue($fixture->userRepository->replaceEmailChangeReservation($other, $next));
                $destination = 'RESERVED@example.test';
            }

            $before = $fixture->userRepository->all();
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command($destination)));
                self::fail('Claimed destination was stolen.');
            } catch (StateConflict) {
                self::assertEquals($before, $fixture->userRepository->all());
                self::assertEquals([$fixture->initial], $fixture->activationGrantRepository->all());
                self::assertSame([], $fixture->auditEvidenceRepository->all());
            }
        }
    }

    public function test_user_grant_audit_and_commit_failures_roll_back_all_owned_state(): void
    {
        foreach (['user', 'grant', 'audit', 'commit'] as $failure) {
            $fixture = new RegistrationRecoveryFixture();
            if ($failure === 'user') {
                $fixture->userRepository = new InMemoryUserRepository(
                    $fixture->unitOfWork
                );
                // Existing target has disappeared by authoritative replacement admission.
            } elseif ($failure === 'grant') {
                $fixture->activationGrantRepository = new InMemoryActivationGrantRepository(
                    $fixture->unitOfWork,
                    replaceWithSuccessorSucceeds: false,
                    activationGrantRepository: $fixture->activationGrantRepository
                );
            } elseif ($failure === 'audit') {
                $fixture->auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
                    $fixture->unitOfWork,
                    failAfterSave: true
                );
            } else {
                $fixture->unitOfWork->failNextCommit = true;
            }

            $before = $fixture->userRepository->all();
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('Injected failure was hidden.');
            } catch (ResourceNotFound | StateConflict | RuntimeException) {
                self::assertEquals($before, $fixture->userRepository->all());
                self::assertEquals([$fixture->initial], $fixture->activationGrantRepository->all());
                self::assertSame([], $fixture->auditEvidenceRepository->all());
                self::assertCount(1, $fixture->eventDispatcher->events());
                self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
                self::assertSame(
                    'Pending registration recovery failed.',
                    $fixture->eventDispatcher->events()[0]->getErrorMessage()
                );
            }
        }
    }

    public function test_inconsistent_pending_password_and_reservation_reject_without_repairing_state(): void
    {
        foreach (['password', 'reservation'] as $inconsistency) {
            $fixture = new RegistrationRecoveryFixture();
            $password = null;
            $reservation = null;
            if ($inconsistency === 'password') {
                $password = PasswordHash::fromString(password_hash('test-password', PASSWORD_BCRYPT));
            } else {
                $reservation = EmailAddress::fromString('reserved@example.test');
            }

            $user = UserFixture::pendingRegistrationState($fixture->initialUser, $password, $reservation);
            $fixture->registration->userState->users = [$user];
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('Recovery repaired inconsistent authority implicitly.');
            } catch (StateConflict) {
                self::assertSame($user, $fixture->userRepository->all()[0]);
                self::assertEquals([$fixture->initial], $fixture->activationGrantRepository->all());
                self::assertSame([], $fixture->auditEvidenceRepository->all());
            }
        }
    }

    public function test_generation_ownership_cannot_be_inferred_from_a_matching_destination(): void
    {
        $fixture = new RegistrationRecoveryFixture();
        $this->expectException(RegistrationRecoveryException::class);
        $fixture->initial->assertRegistrationRecovery(
            UserId::generate(),
            $fixture->initial->getDelivery()->getId(),
            $fixture->initialUser->getEmail(),
            EmailAddress::fromString('corrected@example.test'),
            new DateTimeImmutable('2030-01-02Z')
        );
    }

    public function test_generation_and_cipher_failures_preserve_original_throwable_and_safe_evidence(): void
    {
        foreach (['generate', 'encrypt'] as $failure) {
            $fixture = new RegistrationRecoveryFixture();
            $fault = new RuntimeException('private recovered-registration-credential ciphertext:row');
            $generator = new FixedCredentialGenerator('recovered-registration-credential');
            $cipher = new PrefixInvitationDeliveryCipher();
            if ($failure === 'generate') {
                $generator = $this->createStub(ActivationCredentialGenerator::class);
                $generator->method('generate')->willThrowException($fault);
            } else {
                $cipher = $this->createStub(InvitationDeliveryCipher::class);
                $cipher->method('encrypt')->willThrowException($fault);
            }

            $attempts = [];
            $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use (&$attempts): void {
                $attempts[] = $event;

                throw new RuntimeException('secondary publisher failure');
            });
            $handler = new RecoverPendingRegistrationHandler(
                $fixture->userRepository,
                $fixture->activationGrantRepository,
                $fixture->authorization(),
                $fixture->unitOfWork,
                $generator,
                $cipher,
                new FixedClock('2030-01-02Z'),
                $eventDispatcher,
                $fixture->auditEvidenceRepository
            );
            $command = $fixture->command();
            try {
                $handler->handle(CommandMessage::create($command));
                self::fail('A secret-bearing failure was hidden.');
            } catch (RuntimeException $caught) {
                self::assertSame($fault, $caught);
                self::assertCount(1, $attempts);
                self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
                self::assertSame($command, $attempts[0]->getCommand());
                self::assertSame('Pending registration recovery failed.', $attempts[0]->getErrorMessage());
                self::assertStringNotContainsString(
                    'recovered-registration-credential',
                    serialize($attempts[0]->toArray())
                );
                $this->assertUnchanged($fixture);
            }
        }
    }

    private function assertUnchanged(RegistrationRecoveryFixture $fixture): void
    {
        self::assertEquals([$fixture->initialUser], $fixture->userRepository->all());
        self::assertEquals([$fixture->initial], $fixture->activationGrantRepository->all());
        self::assertSame([], $fixture->auditEvidenceRepository->all());
        foreach ($fixture->eventDispatcher->events() as $event) {
            self::assertInstanceOf(CommandFailedEvent::class, $event);
            self::assertSame('Pending registration recovery failed.', $event->getErrorMessage());
        }
    }
}
