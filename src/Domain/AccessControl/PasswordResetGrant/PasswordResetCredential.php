<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Represents a raw password-reset credential at the synchronous security boundary.
 */
final readonly class PasswordResetCredential
{
    /**
     * Creates a password-reset credential.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a non-empty password-reset credential.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new DomainException('The password-reset credential must not be empty.');
        }

        return new self($value);
    }

    /**
     * Returns the raw credential only to the synchronous security boundary.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
