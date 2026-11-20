<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditSessionRevocation
 *
 * Admits the session identity and revisions, with a reason only for administrative revocation.
 *
 * @phpstan-type SessionRevocationPayload array{session_id: string, before: int, after: int, reason?: string}
 */
final readonly class AuditSessionRevocation implements Arrayable
{
    /**
     * Constructs AuditSessionRevocation
     *
     * @throws DomainException When the revisions do not describe one authoritative revocation
     */
    public function __construct(
        private RefreshSessionId $sessionId,
        private int $before,
        private int $after,
        private ?SessionRevocationReason $reason = null
    ) {
        if ($before < 0 || $before === PHP_INT_MAX || $after !== $before + 1) {
            throw new DomainException('Audit session revocation must advance one revision.');
        }
    }

    /**
     * Creates admitted facts from their complete persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When persisted fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        $hasReason = isset($data['reason']);
        if (
            count($data) !== ($hasReason ? 4 : 3) || !isset($data['session_id'], $data['before'], $data['after'])
            || !is_string($data['session_id']) || !is_int($data['before']) || !is_int($data['after'])
            || ($hasReason && !is_string($data['reason']))
        ) {
            throw new DomainException('Audit session revocation requires its complete current representation.');
        }

        return new self(
            RefreshSessionId::fromString($data['session_id']),
            $data['before'],
            $data['after'],
            $hasReason ? SessionRevocationReason::fromString($data['reason']) : null
        );
    }

    /**
     * Validates reason presence for the audit action
     *
     * @throws DomainException When the presence of a reason does not match the action
     */
    public function assertOutcome(AuditAction $action): void
    {
        if (($action === AuditAction::SESSION_ADMINISTRATIVELY_REVOKED) !== ($this->reason !== null)) {
            throw new DomainException('Only administrative session revocation evidence requires a reason.');
        }
    }

    /**
     * Returns the admitted session revocation facts
     *
     * @phpstan-return SessionRevocationPayload
     */
    public function toArray(): array
    {
        $facts = ['session_id' => $this->sessionId->toString(), 'before' => $this->before, 'after' => $this->after];
        if ($this->reason !== null) {
            $facts['reason'] = $this->reason->toString();
        }

        return $facts;
    }
}
