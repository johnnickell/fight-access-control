<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class FindDueCredentialDeliveries
 *
 * Queries a bounded deterministic page of recoverable credential work.
 */
final readonly class FindDueCredentialDeliveries implements Query
{
    /**
     * Constructs FindDueCredentialDeliveries
     */
    public function __construct(private DateTimeImmutable $at, private int $limit)
    {
        if ($this->limit < 1) {
            throw new DomainException('The credential-delivery query limit must be positive.');
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

        if (!is_string($data['at']) || !is_int($data['limit'])) {
            throw new DomainException('Invalid credential-delivery query data.');
        }

        return new static(CredentialDeliveryTimestamp::fromString($data['at'])->toDateTimeImmutable(), $data['limit']);
    }

    /**
     * Returns the canonical due-work query payload
     *
     * @phpstan-return array{at: string, limit: int}
     */
    public function toArray(): array
    {
        return [
            'at'    => $this->at->format('Y-m-d\TH:i:s.uP'),
            'limit' => $this->limit
        ];
    }

    /**
     * Returns the discovery eligibility boundary
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
