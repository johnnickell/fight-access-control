<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RegisterUserHandler::class)]
final class RegisterUserHandlerTest extends TestCase
{
    public function test_complete_pending_registration_is_visible_before_its_fact_and_never_implies_delivery(): void
    {
        $fixture = null;
        $events = new InMemoryEventDispatcher(static function ($event) use (&$fixture): void {
            self::assertInstanceOf(RegistrationFixture::class, $fixture);
            self::assertInstanceOf(UserRegistered::class, $event);
            self::assertFalse($fixture->unitOfWork->transactionActive);
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertCount(1, $fixture->users->all());
            self::assertCount(1, $fixture->grants->all());
            self::assertCount(1, $fixture->audit->all());
        });
        $fixture = new RegistrationFixture(events: $events);
        $command = $fixture->command();
        $fixture->handler(['ROLE_MEMBER', 'ROLE_READER', 'ROLE_MEMBER'])->handle(CommandMessage::create($command));
        self::assertSame(RegisterUser::class, RegisterUserHandler::commandRegistration());
        self::assertSame(1, $fixture->unitOfWork->transactions);
        $user = $fixture->users->getById($command->getUserId());
        self::assertNotNull($user);
        self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
        self::assertNull($user->getPasswordHash());
        self::assertSame('alice@example.test', $user->getEmail()->canonical());
        self::assertEqualsCanonicalizing([$fixture->member->getId(), $fixture->reader->getId()], $user->getRoleIds());
        $grant = $fixture->grants->all()[0];
        self::assertSame($command->getUserId(), $grant->getUserId());
        self::assertSame(ActivationOrigin::REGISTRATION, $grant->getOrigin());
        self::assertSame(CredentialDeliveryStatus::PENDING, $grant->getDelivery()->getStatus());
        self::assertSame('2030-01-08T00:00:00+00:00', $grant->getExpiresAt()->format(DATE_ATOM));
        self::assertSame('user.registered', $fixture->audit->all()[0]->action());
        self::assertSame('registration', $fixture->audit->all()[0]->actorId());
        self::assertSame($command->getUserId(), $fixture->audit->all()[0]->subjectId());
        self::assertSame([], $fixture->audit->all()[0]->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserRegistered::class, $events->events()[0]);
        self::assertSame($grant->getDelivery()->getId(), $events->events()[0]->getActivationDeliveryId());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidDefaults(): iterable
    {
        yield 'empty' => [[]];
        yield 'invalid name' => [['member']];
        yield 'forbidden' => [['ROLE_MEMBER', 'ROLE_SUPER_ADMIN']];
    }

    /** @param list<string> $names */
    #[DataProvider('invalidDefaults')]
    public function test_configuration_is_validated_at_construction(array $names): void
    {
        $fixture = new RegistrationFixture();
        $this->expectException(DomainException::class);
        try {
            $fixture->handler($names);
        } finally {
            self::assertSame(0, $fixture->unitOfWork->transactions);
            $this->assertNoRegistration($fixture);
            self::assertSame([], $fixture->events->events());
        }
    }

    public function test_missing_role_and_conflicting_stored_name_or_identity_reject_every_effect(): void
    {
        foreach (['missing', 'duplicate name', 'duplicate identity'] as $case) {
            $fixture = new RegistrationFixture();
            $names = ['ROLE_MEMBER', 'ROLE_READER'];
            if ($case === 'missing') {
                $names[] = 'ROLE_ABSENT';
            } else {
                // Model corrupt stored indexes, not a permitted Role creation writer.
                $conflict = Role::define(
                    $case === 'duplicate identity' ? $fixture->member->getId() : RoleId::generate(),
                    RoleName::fromString($case === 'duplicate identity' ? 'ROLE_OTHER' : 'ROLE_MEMBER'),
                    [],
                    new DateTimeImmutable('2030-01-01T00:00:00Z')
                );
                $fixture->roleState->roles[] = $conflict;
                $fixture->unitOfWork->authorizationReferenceState()->addRole($conflict);
                if ($case === 'duplicate identity') {
                    $names = ['ROLE_OTHER'];
                }
            }

            try {
                $fixture->handler($names)->handle(CommandMessage::create($fixture->command()));
                self::fail('Invalid stored Role authority was accepted.');
            } catch (DomainException | RuntimeException) {
            }

            $this->assertNoRegistration($fixture);
            $this->assertSafeFailure($fixture);
        }
    }

    public function test_new_configuration_changes_only_subsequent_registrations_and_resolves_current_names(): void
    {
        $fixture = new RegistrationFixture();
        $first = $fixture->command();
        $fixture->handler(['ROLE_MEMBER'])->handle(CommandMessage::create($first));
        $replacement = $fixture->reader->reconcileManaged(
            RoleName::fromString('ROLE_CURRENT'),
            [],
            new DateTimeImmutable('2030-01-01T00:01:00Z')
        );
        self::assertTrue($fixture->roles->replace($fixture->reader, $replacement));
        $second = $fixture->command('second@example.test');
        $fixture->handler(['ROLE_CURRENT'])->handle(CommandMessage::create($second));
        self::assertEquals([$fixture->member->getId()], $fixture->users->getById($first->getUserId())?->getRoleIds());
        self::assertEquals([$replacement->getId()], $fixture->users->getById($second->getUserId())?->getRoleIds());
    }

    public function test_same_handler_resolves_names_again_without_reassigning_previous_users(): void
    {
        $fixture = new RegistrationFixture();
        $handler = $fixture->handler(['ROLE_MEMBER']);
        $first = $fixture->command();
        $handler->handle(CommandMessage::create($first));
        self::assertTrue($fixture->roles->replace($fixture->member, $fixture->member->renameCustom(
            RoleName::fromString('ROLE_FORMER_MEMBER'),
            new DateTimeImmutable('2030-01-01T00:00:01Z')
        )));
        $newDefault = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_MEMBER'),
            [],
            new DateTimeImmutable('2030-01-01T00:00:01Z')
        );
        $fixture->roles->add($newDefault);
        $second = $fixture->command('second@example.test');
        $handler->handle(CommandMessage::create($second));
        self::assertEquals([$fixture->member->getId()], $fixture->users->getById($first->getUserId())?->getRoleIds());
        self::assertEquals([$newDefault->getId()], $fixture->users->getById($second->getUserId())?->getRoleIds());
    }

    /** @return iterable<string, array{UserState}> */
    public static function claimedStates(): iterable
    {
        foreach (UserState::cases() as $state) {
            yield $state->value => [$state];
        }
    }

    #[DataProvider('claimedStates')]
    public function test_canonical_email_claims_in_every_state_reject_without_recovery(UserState $state): void
    {
        $fixture = new RegistrationFixture();
        $existing = UserFixture::withState('alice@example.test', $state);
        $fixture->users->add($existing);
        $this->expectException(DuplicateEmailException::class);
        try {
            $fixture->handler()->handle(CommandMessage::create($fixture->command('ALICE@example.test')));
        } finally {
            self::assertSame([$existing], $fixture->users->all());
            self::assertSame([], $fixture->grants->all());
            self::assertSame([], $fixture->audit->all());
            $this->assertSafeFailure($fixture);
        }
    }

    public function test_live_destination_reservation_rejects_without_new_delivery_or_identity(): void
    {
        $fixture = new RegistrationFixture();
        $existing = UserFixture::withState('owner@example.test', UserState::ACTIVE);
        $existing->requestEmailChange(EmailAddress::fromString('Alice@example.test'), new DateTimeImmutable());

        $fixture->users->add($existing);
        $this->expectException(DuplicateEmailException::class);
        try {
            $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        } finally {
            self::assertSame([$existing], $fixture->users->all());
            self::assertSame([], $fixture->grants->all());
            self::assertSame([], $fixture->audit->all());
            $this->assertSafeFailure($fixture);
        }
    }

    public function test_partial_insert_grant_audit_and_commit_failures_leave_no_registration(): void
    {
        foreach (['insert', 'grant', 'audit', 'commit'] as $failure) {
            $fault = new RuntimeException('storage diagnostic with registration-fixture-credential');
            $fixture = new RegistrationFixture(
                afterInsert: $failure === 'insert' ? static fn() => throw $fault : null,
                failAudit: $failure === 'audit',
                failGrant: $failure === 'grant'
            );
            $fixture->unitOfWork->failNextCommit = $failure === 'commit';
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('A failed write or commit was accepted.');
            } catch (RuntimeException | LogicException $caught) {
                if ($failure === 'insert') {
                    self::assertSame($fault, $caught);
                }

                if ($failure === 'audit') {
                    self::assertSame($fixture->audit->failure(), $caught);
                }
            }

            $this->assertNoRegistration($fixture);
            $this->assertSafeFailure($fixture);
            // Completion released the creation fence even after late failure.
            $other = new InMemoryUnitOfWork();
            self::assertTrue($other->commitTransactional(fn(): bool => $fixture->competingRoles($other)->remove(
                $fixture->member
            )));
        }
    }

    public function test_original_throwable_survives_even_failure_publication_fault(): void
    {
        $original = new RuntimeException('private credential-bearing storage diagnostic');
        $events = new InMemoryEventDispatcher(static fn() => throw new RuntimeException('publisher failure'));
        $fixture = new RegistrationFixture(afterInsert: static fn() => throw $original, events: $events);
        try {
            $fixture->handler()->handle(CommandMessage::create($fixture->command()));
            self::fail('The storage failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($original, $runtimeException);
        }

        $this->assertNoRegistration($fixture);
    }

    private function assertNoRegistration(RegistrationFixture $fixture): void
    {
        self::assertSame([], $fixture->users->all());
        self::assertSame([], $fixture->grants->all());
        self::assertSame([], $fixture->audit->all());
    }

    private function assertSafeFailure(RegistrationFixture $fixture): void
    {
        self::assertCount(1, $fixture->events->events());
        $failure = $fixture->events->events()[0];
        self::assertInstanceOf(CommandFailedEvent::class, $failure);
        self::assertInstanceOf(RegisterUser::class, $failure->getCommand());
        self::assertSame('User registration failed.', $failure->getErrorMessage());
        foreach (['credential', 'ciphertext', 'diagnostic', 'actor_id', 'role_ids'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, serialize($failure->toArray()));
        }
    }
}
