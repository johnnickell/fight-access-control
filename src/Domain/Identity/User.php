<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\Identity;

/**
 * Represents a stable user identity.
 */
final readonly class User
{
    /**
     * Creates a user identity.
     */
    private function __construct(
        private UserId $id,
        private EmailAddress $email,
        private UserState $state
    ) {
    }

    /**
     * Creates a pending user from an email address.
     */
    public static function invite(string $email): self
    {
        return new self(UserId::generate(), EmailAddress::fromString($email), UserState::PendingActivation);
    }

    /**
     * Returns the stable user identifier.
     */
    public function id(): UserId
    {
        return $this->id;
    }

    /**
     * Returns the canonical email address.
     */
    public function email(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the lifecycle state.
     */
    public function state(): UserState
    {
        return $this->state;
    }
}
