<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Event;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(UserAuthentication::class)]
#[CoversClass(TokenIssuer::class)]
#[CoversClass(PasswordManagement::class)]
#[CoversClass(SessionLifecycle::class)]
#[CoversClass(EmailChangeConfirmation::class)]
#[CoversClass(CreateCustomRoleHandler::class)]
final class OperationFailureContractTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function notificationOutcomes(): iterable
    {
        yield 'available' => [false];
        yield 'unavailable' => [true];
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function authenticationOperations(): iterable
    {
        foreach (
            [
            'activate'       => 'Account activation failed.',
            'login'          => 'Login failed.',
            'refresh'        => 'Session refresh failed.',
            'logout'         => 'Session logout failed.',
            'resetPassword'  => 'Password reset failed.',
            'changePassword' => 'Password change failed.',
            'confirmEmail'   => 'Email change confirmation failed.'
            ] as $operation => $message
        ) {
            foreach ([false, true] as $notificationFails) {
                yield $operation.(int) $notificationFails => [$operation, $message, $notificationFails];
            }
        }
    }

    #[DataProvider('notificationOutcomes')]
    public function test_command_failure_keeps_its_identity_and_safe_round_trip(bool $notificationFails): void
    {
        $primary = new RuntimeException('Synthetic dependency detail.');
        $attempts = [];
        $eventDispatcher = $this->events($attempts, $notificationFails);
        $unitOfWork = $this->createMock(TransactionalUnitOfWork::class);
        $unitOfWork->expects(self::once())->method('commitTransactional')->willThrowException($primary);
        $command = new CreateCustomRole(UserId::generate(), RoleId::generate(), RoleName::fromString('ROLE_EDITOR'));
        $handler = new CreateCustomRoleHandler(
            $this->createStub(RoleRepository::class),
            $this->createStub(Clock::class),
            $unitOfWork,
            $eventDispatcher
        );

        $caught = null;
        try {
            $handler->handle(CommandMessage::create($command));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertCount(1, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
        self::assertSame($command, $attempts[0]->getCommand());
        self::assertSame('Custom role creation failed.', $attempts[0]->getErrorMessage());
        self::assertSame($attempts[0]->toArray(), CommandFailedEvent::fromArray($attempts[0]->toArray())->toArray());
        self::assertStringNotContainsString($primary->getMessage(), serialize($attempts[0]->toArray()));
    }

    /** @param 'activate'|'login'|'refresh'|'logout'|'resetPassword'|'changePassword'|'confirmEmail' $operation */
    #[DataProvider('authenticationOperations')]
    public function test_authentication_failures_keep_allowlisted_context_and_identity(
        string $operation,
        string $message,
        bool $notificationFails
    ): void {
        $primary = new RuntimeException('Synthetic dependency detail.');
        $attempts = [];
        $eventDispatcher = $this->events($attempts, $notificationFails);
        $unitOfWork = $this->createMock(TransactionalUnitOfWork::class);
        $unitOfWork->expects(self::once())->method('commitTransactional')->willThrowException($primary);
        $refreshSessionRepository = $this->createStub(RefreshSessionRepository::class);
        $tokenIssuer = new TokenIssuer(
            $this->createStub(TokenEncoder::class),
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'))
        );
        $authentication = new UserAuthentication(
            $this->createStub(UserRepository::class),
            $this->createStub(ActivationGrantRepository::class),
            $unitOfWork,
            $this->createStub(Clock::class),
            $this->createStub(LoginThrottle::class),
            $this->createStub(RefreshCredentialGenerator::class),
            $this->createStub(PasswordHasher::class),
            $this->createStub(PasswordValidator::class),
            $tokenIssuer,
            PasswordHash::fromString(password_hash('synthetic-password', PASSWORD_DEFAULT)),
            $eventDispatcher
        );
        $passwordManagement = new PasswordManagement(
            $this->createStub(UserRepository::class),
            $this->createStub(PasswordResetGrantRepository::class),
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $this->createStub(Clock::class),
            $this->createStub(PasswordHasher::class),
            $this->createStub(PasswordValidator::class),
            PasswordHash::fromString(password_hash('synthetic-password', PASSWORD_DEFAULT)),
            $eventDispatcher,
            $this->createStub(AuditEvidenceRepository::class)
        );
        $sessionLifecycle = new SessionLifecycle(
            $this->createStub(UserRepository::class),
            $refreshSessionRepository,
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $this->createStub(Clock::class),
            $this->createStub(RefreshCredentialGenerator::class),
            $tokenIssuer,
            AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            $eventDispatcher
        );
        $emailChangeConfirmation = new EmailChangeConfirmation(
            $this->createStub(UserRepository::class),
            $this->createStub(EmailChangeGrantRepository::class),
            $this->createStub(PasswordResetGrantRepository::class),
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $this->createStub(Clock::class),
            $eventDispatcher,
            $this->createStub(AuditEvidenceRepository::class)
        );
        $userId = UserId::generate();
        $caught = null;
        try {
            match ($operation) {
                'activate' => $authentication->activate($userId, 'synthetic-credential', 'synthetic-password'),
                'login' => $authentication->login('person@example.test', 'synthetic-password'),
                'refresh' => $sessionLifecycle->refresh('synthetic-credential'),
                'logout' => $sessionLifecycle->logout('synthetic-credential'),
                'resetPassword' => $passwordManagement->resetPassword(
                    $userId,
                    'synthetic-credential',
                    'synthetic-password',
                    AnonymousId::generate()
                ),
                'changePassword' => $passwordManagement->changePassword(
                    $userId,
                    'synthetic-current',
                    'synthetic-password'
                ),
                'confirmEmail' => $emailChangeConfirmation->confirmEmail(
                    $userId,
                    'synthetic-credential',
                    AnonymousId::generate()
                )
            };
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertCount(1, $attempts);
        self::assertInstanceOf(RedactedCommandFailed::class, $attempts[0]);
        $owner = match ($operation) {
            'activate', 'login' => UserAuthentication::class,
            'refresh', 'logout' => SessionLifecycle::class,
            'changePassword', 'resetPassword' => PasswordManagement::class,
            'confirmEmail' => EmailChangeConfirmation::class
        };
        self::assertSame($owner.'::'.$operation, $attempts[0]->getCommandClass());
        $context = [];
        if (in_array($operation, ['activate', 'resetPassword', 'changePassword', 'confirmEmail'], true)) {
            $context = ['user_id' => $userId->toString()];
        }

        self::assertSame($context, $attempts[0]->getRedactedCommandData());
        self::assertSame($message, $attempts[0]->getErrorMessage());
        $data = $attempts[0]->toArray();
        self::assertSame($data, RedactedCommandFailed::fromArray($data)->toArray());
        $details = [$primary->getMessage(), 'synthetic-credential', 'synthetic-password', 'synthetic-current'];
        foreach ($details as $detail) {
            self::assertStringNotContainsString($detail, serialize($data));
        }
    }

    /** @param list<Event> $attempts */
    private function events(array &$attempts, bool $notificationFails): InMemoryEventDispatcher
    {
        return new InMemoryEventDispatcher(static function (Event $event) use (&$attempts, $notificationFails): void {
            $attempts[] = $event;
            if ($notificationFails) {
                throw new RuntimeException('Synthetic notification failure.');
            }
        });
    }
}
