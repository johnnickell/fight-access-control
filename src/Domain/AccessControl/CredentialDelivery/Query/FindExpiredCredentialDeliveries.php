<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class FindExpiredCredentialDeliveries
 *
 * Queries bounded advisory cleanup work without granting mutation authority.
 */
final readonly class FindExpiredCredentialDeliveries implements Query
{
    /**
     * Constructs FindExpiredCredentialDeliveries
     */
    public function __construct(private DateTimeImmutable $at, private int $limit)
    {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('The expired credential-work limit must be between 1 and 100.');
        }
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['at', 'limit'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        if (!is_string($data['at']) || $data['at'] === '' || !is_int($data['limit'])) {
            throw new DomainException('Invalid expired credential-work query data.');
        }

        return new static(CredentialDeliveryTimestamp::fromString($data['at'])->toDateTimeImmutable(), $data['limit']);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['at' => $this->at->format('Y-m-d\TH:i:s.uP'), 'limit' => $this->limit];
    }

    /**
     * Returns the inclusive expiry boundary
     */
    public function getAt(): DateTimeImmutable
    {
        return $this->at;
    }

    /**
     * Returns the maximum number of work items
     */
    public function getLimit(): int
    {
        return $this->limit;
    }
}
