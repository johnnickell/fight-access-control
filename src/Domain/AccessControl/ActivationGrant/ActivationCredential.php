<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Represents an opaque, single-use activation credential.
 */
final readonly class ActivationCredential
{
    /**
     * Constructs a non-empty activation credential.
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
            throw new DomainException('The activation credential must not be empty.');
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
