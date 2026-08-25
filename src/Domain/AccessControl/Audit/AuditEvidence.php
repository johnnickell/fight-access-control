<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use LogicException;

/**
 * Represents the secret-free durable evidence for a sensitive action.
 *
 * @phpstan-consistent-constructor
 */
class AuditEvidence
{
    /**
     * Creates secret-free durable audit evidence.
     */
    protected function __construct(
        private readonly string $actorId,
        private readonly string $action,
        private readonly UserId|AgentId $subjectId,
        /** @var array<string, string> */
        private readonly array $context = []
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
     * Records the secret-free provisioning of an Agent authority.
     */
    public static function agentProvisioned(string $actorId, AgentId $agentId): static
    {
        return new static($actorId, 'agent.provisioned', $agentId);
    }

    /**
     * Records the reasoned administrative revocation of a user's refresh session.
     */
    public static function administrativeSessionRevocation(
        UserId $actorId,
        UserId $userId,
        RefreshSessionId $refreshSessionId,
        SessionRevocationReason $reason
    ): static {
        return new static(
            $actorId->toString(),
            'refresh_session.administratively_revoked',
            $userId,
            [
                'refresh_session_id' => $refreshSessionId->toString(),
                'reason' => $reason->toString(),
            ]
        );
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
        if (!$this->subjectId instanceof UserId) {
            throw new LogicException('The audit evidence does not have a User subject.');
        }

        return $this->subjectId;
    }

    /**
     * Returns the affected User or Agent identifier.
     */
    public function subjectId(): UserId|AgentId
    {
        return $this->subjectId;
    }

    /**
     * Returns public audit context.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return $this->context;
    }
}
