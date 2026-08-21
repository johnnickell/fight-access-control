<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionRevocationReasonException;

/**
 * Represents bounded, secret-free evidence explaining an administrative session revocation.
 */
final readonly class SessionRevocationReason
{
    private const int MAXIMUM_LENGTH = 500;

    /**
     * Constructs a validated administrative revocation reason.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a reason from user-supplied text after trimming surrounding whitespace.
     */
    public static function fromString(string $value): self
    {
        $value = preg_replace('/\A[\h\v]+|[\h\v]+\z/u', '', $value) ?? '';
        $length = preg_match_all('/./us', $value);
        if ($value === '' || $length === false || $length > self::MAXIMUM_LENGTH) {
            throw new SessionRevocationReasonException(
                'A session revocation reason must contain between 1 and 500 characters.'
            );
        }

        return new self($value);
    }

    /**
     * Returns the validated audit text.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
