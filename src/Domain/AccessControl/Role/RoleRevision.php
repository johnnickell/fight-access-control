<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleRevisionException;
use Fight\Common\Domain\Value\Identifier\Uuid;

/**
 * Class RoleRevision
 *
 * Identifies one state within a randomly unique Role incarnation
 */
final readonly class RoleRevision
{
    public const int MAX_SEQUENCE = 999999999;

    private const string PATTERN = <<<'REGEX'
        /\A([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}):([1-9][0-9]{0,8})\z/
        REGEX;

    /**
     * Constructs RoleRevision
     */
    private function __construct(private string $incarnation, private int $sequence)
    {
    }

    /**
     * Creates the first revision of a new incarnation
     */
    public static function initial(): self
    {
        return new self(Uuid::random()->toString(), 1);
    }

    /**
     * Creates a validated revision from its canonical persisted or caller value
     */
    public static function fromString(mixed $value): self
    {
        if (
            !is_string($value)
            || preg_match(self::PATTERN, $value, $parts) !== 1
        ) {
            throw new RoleRevisionException('The Role revision is invalid.');
        }

        return new self($parts[1], (int) $parts[2]);
    }

    /**
     * Returns the next revision without wrapping or changing incarnation
     */
    public function next(): self
    {
        if ($this->sequence === self::MAX_SEQUENCE) {
            throw new RoleRevisionException('The Role revision is exhausted.');
        }

        return new self($this->incarnation, $this->sequence + 1);
    }

    /**
     * Returns the canonical concurrency value
     */
    public function toString(): string
    {
        return $this->incarnation.':'.$this->sequence;
    }

    /**
     * Determines whether both values identify the same incarnation and state
     */
    public function equals(self $other): bool
    {
        return $this->incarnation === $other->incarnation && $this->sequence === $other->sequence;
    }
}
