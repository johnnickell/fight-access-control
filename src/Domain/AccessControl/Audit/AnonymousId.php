<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Value\Identifier\Uuid;
use Override;

/**
 * Class AnonymousId
 *
 * Identifies a deliberately unauthenticated initiator, not missing provenance.
 */
final readonly class AnonymousId extends UniqueId
{
    /**
     * Creates the fixed anonymous identity
     */
    #[Override]
    public static function generate(): static
    {
        return self::fromString(Uuid::NIL);
    }

    /**
     * Creates the anonymous identity from canonical nil
     *
     * @throws DomainException When the value is not canonical nil
     */
    #[Override]
    public static function fromString(string $value): static
    {
        if ($value !== Uuid::NIL) {
            throw new DomainException('Anonymous identity must be the canonical nil UUID.');
        }

        return new self(Uuid::parse($value));
    }
}
