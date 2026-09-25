<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Class EncryptedCredentialMaterial
 *
 * Carries sensitive encrypted credential material outside secret-free views and messages.
 */
final readonly class EncryptedCredentialMaterial
{
    private SensitiveParameterValue $value;

    /**
     * Constructs EncryptedCredentialMaterial
     */
    private function __construct(#[SensitiveParameter] string $value)
    {
        $this->value = new SensitiveParameterValue($value);
    }

    /**
     * Creates non-empty encrypted credential material
     */
    public static function fromString(#[SensitiveParameter] string $value): self
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
        return (string) $this->value->getValue();
    }

    /**
     * Returns whether encrypted material matches without coercion
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->reveal(), $other->reveal());
    }

    /**
     * Returns a diagnostic representation without encrypted credential material
     *
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /**
     * Prevents encrypted credential material from being serialized
     */
    public function __serialize(): array
    {
        throw new LogicException('Encrypted credential material cannot be serialized.');
    }
}
