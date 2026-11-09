<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Value\Identifier\Uuid;
use Override;

/**
 * Class SystemId
 *
 * Identifies deliberate autonomous work, not an account or permission grant.
 */
final readonly class SystemId extends UniqueId
{
    /**
     * Creates the fixed system identity
     */
    #[Override]
    public static function generate(): static
    {
        return self::fromString(Uuid::NIL);
    }

    /**
     * Creates the system identity from canonical nil
     *
     * @throws DomainException When the value is not canonical nil
     */
    #[Override]
    public static function fromString(string $value): static
    {
        if ($value !== Uuid::NIL) {
            throw new DomainException('System identity must be the canonical nil UUID.');
        }

        return new self(Uuid::parse($value));
    }
}
