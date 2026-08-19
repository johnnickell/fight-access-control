<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

/**
 * Requests creation of a pending user invitation.
 */
final readonly class InvitePendingUser
{
    /**
     * Creates an invitation command.
     */
    public function __construct(
        public string $actorId,
        public string $email
    ) {
    }
}
