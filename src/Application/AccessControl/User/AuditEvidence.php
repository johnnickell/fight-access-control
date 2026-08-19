<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Represents the secret-free durable evidence for a sensitive action.
 */
final readonly class AuditEvidence
{
    /**
     * Records an invitation action without credential material.
     */
    public function __construct(
        private string $actorId,
        private string $action,
        private UserId $userId
    ) {
    }

    /**
     * Returns the actor identity.
     */
    public function actorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the audited action name.
     */
    public function action(): string
    {
        return $this->action;
    }

    /**
     * Returns the affected user identifier.
     */
    public function userId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns public audit context.
     *
     * @return array<never, never>
     */
    public function context(): array
    {
        return [];
    }
}
