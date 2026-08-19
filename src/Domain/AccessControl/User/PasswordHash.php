<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Represents a password hash produced by a supported PHP password algorithm.
 */
final readonly class PasswordHash
{
    /**
     * Constructs a validated password hash.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a password hash from its serialized representation.
     */
    public static function fromString(string $value): self
    {
        $passwordInfo = password_get_info($value);

        if (empty($passwordInfo['algo'])) {
            throw new DomainException('The password hash must use a supported password algorithm.');
        }

        return new self($value);
    }

    /**
     * Returns the serialized password hash.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
