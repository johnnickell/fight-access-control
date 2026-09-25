<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class FindCredentialDeliveryStatus
 *
 * Queries safe operational state for one exact delivery generation.
 */
final readonly class FindCredentialDeliveryStatus implements Query
{
    private const array PURPOSES = ['activation', 'password_reset', 'email_change'];

    /**
     * Constructs FindCredentialDeliveryStatus
     */
    public function __construct(private string $purpose, private string $deliveryId)
    {
        if (!in_array($this->purpose, self::PURPOSES, true)) {
            throw new DomainException('The credential-delivery purpose is unsupported.');
        }

        if ($this->deliveryId === '') {
            throw new DomainException('The credential-delivery identifier must not be empty.');
        }
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['purpose', 'delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static((string) $data['purpose'], (string) $data['delivery_id']);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'purpose'     => $this->purpose,
            'delivery_id' => $this->deliveryId
        ];
    }

    /**
     * Returns the credential purpose
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Returns the exact delivery-generation identifier
     */
    public function getDeliveryId(): string
    {
        return $this->deliveryId;
    }
}
