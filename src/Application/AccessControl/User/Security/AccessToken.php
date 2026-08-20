<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Security;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Carries one encoded short-lived access JWT.
 */
final readonly class AccessToken
{
    /**
     * Constructs a non-empty encoded token.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates an access token from its encoded representation.
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new DomainException('The access token must not be empty.');
        }

        return new self($value);
    }

    /**
     * Returns the encoded access token.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
