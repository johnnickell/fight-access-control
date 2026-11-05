<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Command;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RevokeSession
 *
 * Requests self-service or reasoned administrative revocation of an active refresh session.
 */
final readonly class RevokeSession implements Command
{
    /**
     * Constructs RevokeSession
     *
     * Creates a session-revocation command.
     */
    public function __construct(
        private UserId $actorId,
        private RefreshSessionId $currentSessionId,
        private RefreshSessionId $targetSessionId,
        private ?SessionRevocationReason $reason = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'current_session_id', 'target_session_id', 'reason'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        foreach (['actor_id', 'current_session_id', 'target_session_id'] as $key) {
            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid string "%s" in session revocation data', $key));
            }
        }

        if ($data['reason'] !== null && !is_string($data['reason'])) {
            throw new DomainException('The revocation reason must be a string or null.');
        }

        return new static(
            UserId::fromString($data['actor_id']),
            RefreshSessionId::fromString($data['current_session_id']),
            RefreshSessionId::fromString($data['target_session_id']),
            $data['reason'] === null ? null : SessionRevocationReason::fromString($data['reason'])
        );
    }

    /**
     * Returns the canonical session-revocation payload
     *
     * @phpstan-return array{
     *     actor_id: string, current_session_id: string, target_session_id: string, reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'           => $this->actorId->toString(),
            'current_session_id' => $this->currentSessionId->toString(),
            'target_session_id'  => $this->targetSessionId->toString(),
            'reason'             => $this->reason?->toString()
        ];
    }

    /**
     * Returns the user requesting revocation
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the refresh session used for this request
     */
    public function getCurrentSessionId(): RefreshSessionId
    {
        return $this->currentSessionId;
    }

    /**
     * Returns the refresh session selected for revocation
     */
    public function getTargetSessionId(): RefreshSessionId
    {
        return $this->targetSessionId;
    }

    /**
     * Returns the optional administrative revocation reason
     */
    public function getReason(): ?SessionRevocationReason
    {
        return $this->reason;
    }
}
