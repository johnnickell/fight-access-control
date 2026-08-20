<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginClock;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Application\AccessControl\User\Service\PasswordVerifier;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\Login;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\LoginRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Establishes one authoritative refresh session for a verified active user.
 */
final readonly class LoginHandler implements CommandHandler
{
    /**
     * Creates the login handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private RefreshSessionRepository $refreshSessionRepository,
        private UnitOfWork $unitOfWork,
        private LoginClock $clock,
        private LoginThrottle $loginThrottle,
        private PasswordVerifier $passwordVerifier,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return Login::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var Login $command */
        $command = $commandMessage->payload();

        try {
            /** @var array{UserId, RefreshSessionId, DateTimeImmutable} $login */
            $login = $this->unitOfWork->commitTransactional(function () use ($command): array {
                $user = $this->userRepository->getByEmail($command->getEmail());
                $passwordHash = $user?->getPasswordHash();
                $isActiveUser = $user instanceof User
                    && $user->getState() === UserState::ACTIVE
                    && $passwordHash instanceof PasswordHash;
                $loginIsAllowed = $this->loginThrottle->allows($command->getEmail());
                if ($loginIsAllowed && $isActiveUser) {
                    $credentialsMatch = $this->passwordVerifier->matches($command->getSecret(), $passwordHash);
                } else {
                    $credentialsMatch = $this->passwordVerifier->matchesDummy($command->getSecret());
                }

                if (
                    $isActiveUser === false
                    || $credentialsMatch === false
                    || $loginIsAllowed === false
                ) {
                    throw new LoginRejectedException('Login rejected.');
                }

                $loggedInAt = $this->clock->now();
                $refreshSessionId = RefreshSessionId::generate();
                $refreshSession = RefreshSession::start($refreshSessionId, $user->getId(), $loggedInAt);
                $this->refreshSessionRepository->add($refreshSession);

                return [$user->getId(), $refreshSessionId, $loggedInAt];
            });

            $this->eventDispatcher->trigger(new UserLoggedIn(...$login));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }
}
