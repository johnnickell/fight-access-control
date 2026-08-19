<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationClock;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\ActivateInvitedAccount;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use LogicException;
use Throwable;

/**
 * Atomically activates an invited identity and establishes its first refresh session.
 */
final readonly class ActivateInvitedAccountHandler implements CommandHandler
{
    /**
     * Creates the account-activation handler.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private RefreshSessionRepository $refreshSessionRepository,
        private UnitOfWork $unitOfWork,
        private PasswordHasher $passwordHasher,
        private ActivationClock $clock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return ActivateInvitedAccount::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ActivateInvitedAccount $command */
        $command = $commandMessage->payload();

        try {
            /** @var array{RefreshSessionId, DateTimeImmutable} $activation */
            $activation = $this->unitOfWork->commitTransactional(function () use ($command): array {
                $activatedAt = $this->clock->now();
                $user = $this->userRepository->getById($command->getUserId());
                $grant = $this->activationGrantRepository->getByUserId($command->getUserId());

                if (
                    !$user instanceof User
                    || $user->getId()->equals($command->getUserId()) === false
                    || !$grant instanceof ActivationGrant
                    || $grant->getUserId()->equals($command->getUserId()) === false
                    || $grant->purpose() !== 'activation'
                    || $grant->isUsableAt($activatedAt) === false
                    || $grant->matchesCredential($command->getActivationCredential()) === false
                ) {
                    throw new LogicException('The activation grant cannot activate this invited account.');
                }

                $passwordHash = $this->passwordHasher->hash($command->getInitialPassword());
                $consumedGrant = $grant->consume($activatedAt);
                $refreshSessionId = RefreshSessionId::generate();
                $refreshSession = RefreshSession::start(
                    $refreshSessionId,
                    $command->getUserId(),
                    $activatedAt
                );
                $this->activationGrantRepository->replaceConsumed($grant, $consumedGrant);
                $this->refreshSessionRepository->add($refreshSession);
                $user->activate($passwordHash);

                return [$refreshSessionId, $activatedAt];
            });

            $this->eventDispatcher->trigger(new UserActivated($command->getUserId(), ...$activation));
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new RedactedCommandFailed(
                $command::class,
                ['user_id' => $command->getUserId()->toString()],
                $throwable->getMessage()
            ));
            throw $throwable;
        }
    }
}
