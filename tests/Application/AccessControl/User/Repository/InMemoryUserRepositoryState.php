<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Shares durable User state across independent in-memory transaction contexts.
 */
final class InMemoryUserRepositoryState
{
    /** @var list<User> */
    public array $users = [];

    /** @var array<string, object> */
    private array $authenticationAuthorityFenceOwners = [];

    private int $blockedAuthenticationAuthorityFenceAttempts = 0;

    public function acquireAuthenticationAuthorityFence(UserId $userId, object $owner): bool
    {
        $key = $userId->toString();
        $currentOwner = $this->authenticationAuthorityFenceOwners[$key] ?? null;
        if ($currentOwner === null) {
            $this->authenticationAuthorityFenceOwners[$key] = $owner;

            return true;
        }

        if ($currentOwner === $owner) {
            return true;
        }

        ++$this->blockedAuthenticationAuthorityFenceAttempts;

        return false;
    }

    public function releaseAuthenticationAuthorityFence(UserId $userId, object $owner): void
    {
        $key = $userId->toString();
        if (($this->authenticationAuthorityFenceOwners[$key] ?? null) === $owner) {
            unset($this->authenticationAuthorityFenceOwners[$key]);
        }
    }

    public function getBlockedAuthenticationAuthorityFenceAttempts(): int
    {
        return $this->blockedAuthenticationAuthorityFenceAttempts;
    }

    public function isAuthenticationAuthorityFenceHeld(UserId $userId): bool
    {
        return isset($this->authenticationAuthorityFenceOwners[$userId->toString()]);
    }
}
