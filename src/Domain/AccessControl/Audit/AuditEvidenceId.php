<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Value\Identifier\Uuid;
use Override;

/**
 * Class AuditEvidenceId
 *
 * Identifies one evidence record, not a replay or authorization capability.
 */
final readonly class AuditEvidenceId extends UniqueId
{
    /**
     * Creates a non-nil evidence identity
     *
     * @throws DomainException When the identity is nil
     */
    #[Override]
    public static function fromString(string $value): static
    {
        $uuid = Uuid::parse($value);
        if ($uuid->toString() === Uuid::NIL) {
            throw new DomainException('Audit evidence identity must not be nil.');
        }

        return new self($uuid);
    }
}
