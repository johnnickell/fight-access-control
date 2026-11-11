<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\DisableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(CorrectPendingInvitationHandler::class)]
#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(EnableUserHandler::class)]
#[CoversClass(RecoverPendingRegistrationHandler::class)]
#[CoversClass(RestoreUserHandler::class)]
final class UserLifecycleContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function readers(): iterable
    {
        foreach (['enable', 'disable', 'delete', 'restore', 'correct', 'recover'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('readers')]
    public function test_wrong_user_identity_is_an_internal_fault_before_any_write(string $operation): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('getById')->willReturn($this->user($operation));
        foreach (['replaceLifecycleState', 'replacePendingInvitationEmail', 'replacePendingRegistration'] as $method) {
            $userRepository->expects(self::never())->method($method);
        }

        [$handler, $command, $eventDispatcher] = $this->reader($operation, $userRepository);
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('An unrelated User was accepted.');
        } catch (Throwable $throwable) {
            self::assertSame(LogicException::class, $throwable::class);
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    #[DataProvider('readers')]
    public function test_genuine_missing_user_is_deliberate_absence_without_any_audit(string $operation): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('getById')->willReturn(null);
        [$handler, $command] = $this->reader($operation, $repository);
        $this->expectException(ResourceNotFound::class);
        $handler->handle(CommandMessage::create($command));
    }

    /** @return iterable<string, array{string}> */
    public static function activationReaders(): iterable
    {
        yield 'correct' => ['correct'];
        yield 'recover' => ['recover'];
    }

    #[DataProvider('activationReaders')]
    public function test_wrong_grant_owner_is_an_internal_fault_before_any_write(string $operation): void
    {
        $user = $this->user($operation);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('getById')->willReturn($user);
        foreach (['replacePendingInvitationEmail', 'replacePendingRegistration'] as $method) {
            $userRepository->expects(self::never())->method($method);
        }

        $grant = $this->grant($operation, UserId::generate());
        [$handler, $command] = $this->reader($operation, $userRepository, $user->getId(), $grant);
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('Unrelated activation authority was accepted.');
        } catch (Throwable $throwable) {
            self::assertSame(LogicException::class, $throwable::class);
        }
    }

    #[DataProvider('readers')]
    public function test_repository_faults_are_not_classified_as_business_refusals(string $operation): void
    {
        $fault = new UserLifecycleException('Synthetic provider diagnostic', 404);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())->method('getById')->willThrowException($fault);
        [$handler, $command] = $this->reader($operation, $userRepository);
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('A repository fault was swallowed.');
        } catch (Throwable $throwable) {
            self::assertSame($fault, $throwable);
        }
    }

    private function user(string $operation): User
    {
        $state = match ($operation) {
            'enable' => UserState::DISABLED,
            'restore' => UserState::DELETED,
            'correct', 'recover' => UserState::PENDING_ACTIVATION,
            default => UserState::ACTIVE
        };

        return UserFixture::withState('current@example.test', $state);
    }

    /** @return array{CommandHandler, Command, InMemoryEventDispatcher} */
    private function reader(
        string $operation,
        UserRepository $userRepository,
        ?UserId $userId = null,
        ?ActivationGrant $grant = null
    ): array {
        $actorId = UserId::generate();
        $userId ??= UserId::generate();
        $email = EmailAddress::fromString('next@example.test');
        $grantRepository = $this->createStub(ActivationGrantRepository::class);
        $grant ??= $this->grant($operation, $userId);
        $grantRepository->method('getLatestByUserId')->willReturn($grant);
        $audit = $this->createMock(AuditEvidenceRepository::class);
        $audit->expects(self::never())->method('add');
        $unitOfWork = new InMemoryUnitOfWork();
        $eventDispatcher = new InMemoryEventDispatcher();
        $clock = new FixedClock('2030-01-08T00:00:00Z');
        $generator = new FixedCredentialGenerator('new-synthetic-activation');
        $cipher = new PrefixInvitationDeliveryCipher();
        $sessions = new SessionRevocationService($this->createStub(RefreshSessionRepository::class));
        $resets = $this->createStub(PasswordResetGrantRepository::class);
        $handler = match ($operation) {
            'enable' => new EnableUserHandler($userRepository, $unitOfWork, $eventDispatcher, $clock, $audit),
            'disable' => new DisableUserHandler(
                $userRepository,
                $sessions,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $resets,
                $audit
            ),
            'delete' => new DeleteUserHandler(
                $userRepository,
                $sessions,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $resets,
                $audit
            ),
            'restore' => new RestoreUserHandler(
                $userRepository,
                $grantRepository,
                $generator,
                $cipher,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $audit
            ),
            'correct' => new CorrectPendingInvitationHandler(
                $userRepository,
                $grantRepository,
                $this->createStub(InvitationAdministrationAuthorization::class),
                $unitOfWork,
                $generator,
                $cipher,
                $clock,
                $eventDispatcher,
                $audit
            ),
            default => new RecoverPendingRegistrationHandler(
                $userRepository,
                $grantRepository,
                $this->createStub(RegistrationRecoveryAuthorization::class),
                $unitOfWork,
                $generator,
                $cipher,
                $clock,
                $eventDispatcher,
                $audit
            )
        };
        $command = match ($operation) {
            'enable' => new EnableUser($actorId, $userId),
            'disable' => new DisableUser($actorId, $userId),
            'delete' => new DeleteUser($actorId, $userId),
            'restore' => new RestoreUser($actorId, $userId, UserState::ACTIVE),
            'correct' => new CorrectPendingInvitation($actorId, $userId, $email),
            default => new RecoverPendingRegistration($actorId, $userId, $grant->getDelivery()->getId(), $email)
        };

        return [$handler, $command, $eventDispatcher];
    }

    private function grant(string $operation, UserId $userId): ActivationGrant
    {
        return ActivationGrant::issue(
            $operation === 'recover' ? ActivationOrigin::REGISTRATION : ActivationOrigin::PRIVATE_INVITATION,
            $userId,
            ActivationCredential::fromString('synthetic-activation'),
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
            new DateTimeImmutable('2030-01-08T00:00:00Z'),
            EmailAddress::fromString('current@example.test'),
            'ciphertext:synthetic-activation'
        );
    }
}
