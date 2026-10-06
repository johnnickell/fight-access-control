<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateTimeImmutable;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class CredentialDeliveryTimestamp
 *
 * Represents an explicit RFC3339 instant in a credential-delivery payload, without clock inference.
 */
final readonly class CredentialDeliveryTimestamp
{
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?';

    private const string OFFSET_PATTERN = '(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/iD';

    /**
     * Constructs CredentialDeliveryTimestamp
     *
     * Constructs a validated credential-delivery timestamp
     */
    private function __construct(private DateTimeImmutable $value)
    {
    }

    /**
     * Creates an explicit instant, rejecting relative time and calendar normalization
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::DATE_PATTERN.self::OFFSET_PATTERN, $value) !== 1) {
            throw new DomainException('An explicit RFC3339 credential-delivery timestamp is required.');
        }

        $instant = new DateTimeImmutable($value);
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new DomainException('The credential-delivery timestamp is not a valid calendar instant.');
        }

        return new self($instant);
    }

    /**
     * Returns the validated native immutable instant
     */
    public function toDateTimeImmutable(): DateTimeImmutable
    {
        return $this->value;
    }
}
