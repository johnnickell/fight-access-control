<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Represents a stable user identity.
 */
class User
{
    /**
     * Creates a user identity.
     */
    protected function __construct(
        private readonly UserId $id,
        private readonly EmailAddress $email,
        private readonly UserState $state
    ) {
    }

    /**
     * Creates a pending user from an email address.
     */
    public static function invite(UserId $id, EmailAddress $email): self
    {
        return new self($id, $email, UserState::PENDING_ACTIVATION);
    }

    /**
     * Returns the stable user identifier.
     */
    public function getId(): UserId
    {
        return $this->id;
    }

    /**
     * Returns the canonical email address.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the lifecycle state.
     */
    public function getState(): UserState
    {
        return $this->state;
    }
}
