<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Represents the secret-free durable evidence for a sensitive action.
 *
 * @phpstan-consistent-constructor
 */
class AuditEvidence
{
    /**
     * Records an invitation action without credential material.
     */
    protected function __construct(
        private readonly string $actorId,
        private readonly string $action,
        private readonly UserId $userId
    ) {
    }

    /**
     * Records secret-free evidence for a sensitive action.
     */
    public static function record(string $actorId, string $action, UserId $userId): static
    {
        return new static($actorId, $action, $userId);
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
