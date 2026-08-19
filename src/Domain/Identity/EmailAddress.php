<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\Identity;

use InvalidArgumentException;

/**
 * Represents a canonical login email address.
 */
final readonly class EmailAddress
{
    /**
     * Creates a canonical email address.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Canonicalizes a valid email address.
     */
    public static function fromString(string $email): self
    {
        $canonicalEmail = strtolower(trim($email));
        if (filter_var($canonicalEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        return new self($canonicalEmail);
    }

    /**
     * Returns the canonical email value.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Reports whether another email represents the same canonical identity.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
