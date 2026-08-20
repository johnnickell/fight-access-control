<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Represents one opaque refresh credential presented only at the authentication boundary.
 */
final readonly class RefreshCredential
{
    /**
     * Constructs a validated refresh credential.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a refresh credential from its transport value.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new DomainException('The refresh credential must be a 256-bit hexadecimal value.');
        }

        return new self($value);
    }

    /**
     * Returns the raw credential for immediate transport only.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Returns the stable one-way digest used by authoritative storage.
     */
    public function digest(): string
    {
        return hash('sha256', $this->value);
    }
}
