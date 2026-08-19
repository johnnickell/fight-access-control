<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\Identity;

use InvalidArgumentException;

/**
 * Represents a stable user identifier.
 */
final readonly class UserId
{
    /**
     * Creates a user identifier.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a new stable user identifier.
     */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    /**
     * Reconstitutes a stable user identifier.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('A user identifier is required.');
        }

        return new self($value);
    }

    /**
     * Returns the stable identifier value.
     */
    public function value(): string
    {
        return $this->value;
    }
}
