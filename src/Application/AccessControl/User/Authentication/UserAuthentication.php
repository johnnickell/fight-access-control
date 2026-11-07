<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Authentication;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Class UserAuthentication
 *
 * Coordinates synchronous activation and login with coupled authority and session persistence.
 */
final readonly class UserAuthentication
{
    /**
     * Constructs UserAuthentication
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private Clock $clock,
        private LoginThrottle $loginThrottle,
        private RefreshCredentialGenerator $refreshCredentialGenerator,
        private PasswordHasher $passwordHasher,
        private PasswordValidator $passwordValidator,
        private TokenIssuer $tokenIssuer,
        private PasswordHash $dummyPasswordHash,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * Enables an invited identity and returns its first token set
     */
    public function activate(
        UserId $userId,
        #[SensitiveParameter] string $activationCredential,
        #[SensitiveParameter] string $plainPassword,
        bool $remember = false
    ): TokenSet {
        try {
            /** @var array{TokenSet, DateTimeImmutable} $activation */
            $activation = $this->unitOfWork->commitTransactional(function () use (
                $userId,
                $activationCredential,
                $plainPassword,
                $remember
            ): array {
                $authenticatedAt = $this->clock->now();
                $user = $this->userRepository->getById($userId);
                $grant = $this->activationGrantRepository->getLatestByUserId($userId);
                if ($user !== null && !$user->getId()->equals($userId)) {
                    throw new LogicException('The User lookup returned a different identity.');
                }

                if ($grant !== null && (!$grant->getUserId()->equals($userId) || $grant->purpose() !== 'activation')) {
                    throw new LogicException('The activation lookup returned unrelated authority.');
                }

                try {
                    $credential = ActivationCredential::fromString($activationCredential);
                } catch (DomainException) {
                    throw new AuthenticationRequired('Activation rejected.');
                }

                if (
                    !$user instanceof User
                    || $user->getState() !== UserState::PENDING_ACTIVATION
                    || !$grant instanceof ActivationGrant
                    || !$grant->isUsableAt($authenticatedAt)
                    || !$grant->matchesCredential($credential)
                ) {
                    throw new AuthenticationRequired('Activation rejected.');
                }

                $passwordHash = PasswordHash::fromString($this->passwordHasher->hash($plainPassword));
                $replacementUser = clone $user;
                $replacementUser->activate($passwordHash, $authenticatedAt);

                $refreshCredential = $this->refreshCredentialGenerator->generate();
                $refreshSession = $this->tokenIssuer->startSession(
                    $replacementUser,
                    $refreshCredential,
                    $authenticatedAt,
                    $remember
                );
                $tokenSet = $this->tokenIssuer->issue(
                    $replacementUser,
                    $refreshSession,
                    $refreshCredential,
                    $authenticatedAt
                );
                $consumedGrant = $grant->consume($authenticatedAt);
                if (!$this->activationGrantRepository->replace($grant, $consumedGrant)) {
                    throw new StateConflict('The activation grant changed concurrently.');
                }

                if (
                    !$this->userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                        $user,
                        $replacementUser,
                        $refreshSession
                    )
                ) {
                    throw new StateConflict('The invited account changed concurrently.');
                }

                return [$tokenSet, $authenticatedAt];
            });

            $this->eventDispatcher->trigger(new UserActivated(
                $userId,
                $activation[0]->getRefreshSessionId(),
                $activation[1]
            ));

            return $activation[0];
        } catch (Throwable $throwable) {
            $this->publishFailure('activate', ['user_id' => $userId->toString()], 'Account activation failed.');
            throw $throwable;
        }
    }

    /**
     * Verifies an active identity and returns a new token set
     */
    public function login(
        string $email,
        #[SensitiveParameter] string $plainPassword,
        bool $remember = false
    ): TokenSet {
        try {
            /** @var array{TokenSet, DateTimeImmutable} $login */
            $login = $this->unitOfWork->commitTransactional(function () use (
                $email,
                $plainPassword,
                $remember
            ): array {
                try {
                    $emailAddress = EmailAddress::fromString($email);
                } catch (DomainException) {
                    throw new AuthenticationRequired('Login rejected.');
                }

                $user = $this->userRepository->getByEmail($emailAddress);
                if ($user !== null && $user->getEmail()->canonical() !== $emailAddress->canonical()) {
                    throw new LogicException('The User lookup returned a different canonical email.');
                }

                $passwordHash = $user?->getPasswordHash();
                $isActiveUser = $user instanceof User
                    && $user->getState() === UserState::ACTIVE
                    && $passwordHash instanceof PasswordHash;
                $loginIsAllowed = $this->loginThrottle->allows($emailAddress);
                $verificationHash = $this->dummyPasswordHash;
                if ($loginIsAllowed && $isActiveUser) {
                    $verificationHash = $passwordHash;
                }

                $credentialsMatch = $this->passwordValidator->validate(
                    $plainPassword,
                    $verificationHash->toString()
                );

                if (!$isActiveUser || !$credentialsMatch || !$loginIsAllowed) {
                    throw new AuthenticationRequired('Login rejected.');
                }

                $replacementUser = clone $user;
                $authenticatedAt = $this->clock->now();
                $rehashedPassword = null;
                if ($this->passwordValidator->needsRehash($passwordHash->toString())) {
                    $rehashedPassword = PasswordHash::fromString($this->passwordHasher->hash($plainPassword));
                }

                $replacementUser->recordSuccessfulLogin($authenticatedAt, $rehashedPassword);

                $refreshCredential = $this->refreshCredentialGenerator->generate();
                $refreshSession = $this->tokenIssuer->startSession(
                    $replacementUser,
                    $refreshCredential,
                    $authenticatedAt,
                    $remember
                );
                $tokenSet = $this->tokenIssuer->issue(
                    $replacementUser,
                    $refreshSession,
                    $refreshCredential,
                    $authenticatedAt
                );
                if (
                    !$this->userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                        $user,
                        $replacementUser,
                        $refreshSession
                    )
                ) {
                    throw new AuthenticationRequired('Login rejected.');
                }

                return [$tokenSet, $authenticatedAt];
            });

            $this->eventDispatcher->trigger(new UserLoggedIn(
                $login[0]->getUserId(),
                $login[0]->getRefreshSessionId(),
                $login[1]
            ));

            return $login[0];
        } catch (Throwable $throwable) {
            $this->publishFailure('login', [], 'Login failed.');
            throw $throwable;
        }
    }

    /**
     * Dispatches only allowlisted operation context after a sensitive failure
     *
     * @phpstan-param array{user_id?: string} $safeData
     */
    private function publishFailure(string $operation, array $safeData, string $failureMessage): void
    {
        try {
            $this->eventDispatcher->trigger(new RedactedCommandFailed(
                self::class.'::'.$operation,
                $safeData,
                $failureMessage
            ));
        } catch (Throwable) {
            // Notification failure must not replace the original operation failure.
        }
    }
}
