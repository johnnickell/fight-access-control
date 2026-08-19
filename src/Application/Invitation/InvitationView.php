<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

/**
 * Provides the safe observable result of an invitation.
 */
final readonly class InvitationView
{
    /**
     * Creates an invitation result.
     */
    public function __construct(
        private string $email,
        private string $state
    ) {
    }

    /**
     * Returns the canonical invited email address.
     */
    public function email(): string
    {
        return $this->email;
    }

    /**
     * Returns the resulting lifecycle state.
     */
    public function state(): string
    {
        return $this->state;
    }
}
