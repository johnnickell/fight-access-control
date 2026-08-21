<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Represents an opaque password-reset credential.
 */
final readonly class PasswordResetCredential
{
    /**
     * Constructs a non-empty password-reset credential.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a credential from its transport representation.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new DomainException('The password-reset credential must not be empty.');
        }

        return new self($value);
    }

    /**
     * Returns the opaque credential value.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
