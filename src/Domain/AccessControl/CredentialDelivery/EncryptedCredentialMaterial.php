<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use InvalidArgumentException;

/**
 * Class EncryptedCredentialMaterial
 *
 * Carries sensitive encrypted credential material outside secret-free views and messages.
 */
final readonly class EncryptedCredentialMaterial
{
    /**
     * Constructs EncryptedCredentialMaterial
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates non-empty encrypted credential material
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Encrypted credential material must not be empty.');
        }

        return new self($value);
    }

    /**
     * Reveals encrypted material only at an approved persistence or invocation boundary
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Returns whether encrypted material matches without coercion
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
