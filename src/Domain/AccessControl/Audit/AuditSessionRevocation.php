<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditSessionRevocation
 *
 * Admits only session identity and revisions. Free-text administrative reasons are deliberately omitted,
 * not assumed secret-free because they passed business validation.
 *
 * @phpstan-type SessionRevocationPayload array{session_id: string, before: int, after: int}
 */
final readonly class AuditSessionRevocation implements Arrayable
{
    /**
     * Constructs AuditSessionRevocation
     *
     * @throws DomainException When the revisions do not describe one authoritative revocation
     */
    public function __construct(private RefreshSessionId $sessionId, private int $before, private int $after)
    {
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
        if (
            count($data) !== 3 || !isset($data['session_id'], $data['before'], $data['after'])
            || !is_string($data['session_id']) || !is_int($data['before']) || !is_int($data['after'])
        ) {
            throw new DomainException('Audit session revocation requires its complete current representation.');
        }

        return new self(RefreshSessionId::fromString($data['session_id']), $data['before'], $data['after']);
    }

    /**
     * Returns only admitted session relationship and revision facts
     *
     * @phpstan-return SessionRevocationPayload
     */
    public function toArray(): array
    {
        return ['session_id' => $this->sessionId->toString(), 'before' => $this->before, 'after' => $this->after];
    }
}
