<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Represents a raw email-change confirmation credential.
 */
final readonly class EmailChangeCredential
{
    /**
     * Creates an email-change credential.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a non-empty email-change credential.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new DomainException('The email-change credential must not be empty.');
        }

        return new self($value);
    }

    /**
     * Returns the raw credential to the immediate security boundary.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
