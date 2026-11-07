<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Authentication;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use LogicException;
use SensitiveParameter;

/**
 * Class TokenIssuer
 *
 * Creates session lifetimes and matching token material without persisting or granting authority.
 */
final readonly class TokenIssuer
{
    /**
     * Constructs TokenIssuer
     */
    public function __construct(
        private TokenEncoder $tokenEncoder,
        private AuthenticationTokenPolicy $tokenPolicy
    ) {
    }

    /**
     * Creates one authoritative refresh session using configured lifetime policy
     */
    public function startSession(
        User $user,
        #[SensitiveParameter] RefreshCredential $refreshCredential,
        DateTimeImmutable $authenticatedAt,
        bool $remember
    ): RefreshSession {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            $refreshCredential,
            $authenticatedAt,
            $this->tokenPolicy->refreshIdleExpiresAt($authenticatedAt, $remember),
            $this->tokenPolicy->refreshAbsoluteExpiresAt($authenticatedAt, $remember),
            $user->getAuthenticationVersion(),
            $remember
        );
    }

    /**
     * Creates safe access and refresh material for one authoritative session
     */
    public function issue(
        User $user,
        RefreshSession $refreshSession,
        #[SensitiveParameter] RefreshCredential $refreshCredential,
        DateTimeImmutable $authenticatedAt
    ): TokenSet {
        if (
            !$refreshSession->getUserId()->equals($user->getId())
            || $refreshSession->getAuthenticationVersion() !== $user->getAuthenticationVersion()
            || !$refreshSession->matchesCredential($refreshCredential)
            || !$refreshSession->isUsableAt($authenticatedAt)
        ) {
            throw new LogicException('Token issuance requires matching current session authority.');
        }

        $accessTokenExpiresAt = $this->tokenPolicy->accessExpiresAt($authenticatedAt);
        $accessToken = $this->tokenEncoder->encode([
            'auth_version' => $user->getAuthenticationVersion(),
            'iat'          => $authenticatedAt->getTimestamp(),
            'sid'          => $refreshSession->getId()->toString(),
            'sub'          => $user->getId()->toString(),
            'type'         => 'access'
        ], $accessTokenExpiresAt);

        return new TokenSet(
            $user->getId(),
            $refreshSession->getId(),
            $refreshCredential,
            $refreshSession->getAbsoluteExpiresAt(),
            $refreshSession->isRemembered(),
            AccessToken::fromString($accessToken),
            $accessTokenExpiresAt
        );
    }
}
