<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditGrantState
 *
 * Retains a grant revision and its delivery relationship without credential or recipient data.
 *
 * @phpstan-type GrantStatePayload array{kind: string, id: string, revision: int, delivery_id: string}
 */
final readonly class AuditGrantState implements Arrayable
{
    /**
     * Constructs AuditGrantState
     *
     * @throws DomainException When grant and delivery kinds differ or the revision is invalid
     */
    public function __construct(
        private ActivationGrantId|PasswordResetGrantId|EmailChangeGrantId $id,
        private int $revision,
        private ActivationDeliveryId|PasswordResetDeliveryId|EmailChangeDeliveryId $deliveryId
    ) {
        if (
            $revision < 0
            || !match (true) {
                $id instanceof ActivationGrantId => $deliveryId instanceof ActivationDeliveryId,
                $id instanceof PasswordResetGrantId => $deliveryId instanceof PasswordResetDeliveryId,
                $id instanceof EmailChangeGrantId => $deliveryId instanceof EmailChangeDeliveryId
            }
        ) {
            throw new DomainException('Audit grant revision or delivery relationship is invalid.');
        }
    }

    /**
     * Creates a reference from an authoritative grant without retaining its material
     */
    public static function fromGrant(ActivationGrant|PasswordResetGrant|EmailChangeGrant $grant): self
    {
        return new self($grant->getId(), $grant->getRevision(), $grant->getDelivery()->getId());
    }

    /**
     * Creates the complete typed grant relationship from persistence
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When persisted fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 4 || !isset($data['kind'], $data['id'], $data['revision'], $data['delivery_id'])
            || !is_string($data['kind']) || !is_string($data['id'])
            || !is_int($data['revision']) || !is_string($data['delivery_id'])
        ) {
            throw new DomainException('Audit grant requires its complete current representation.');
        }

        return match ($data['kind']) {
            'activation' => new self(
                ActivationGrantId::fromString($data['id']),
                $data['revision'],
                ActivationDeliveryId::fromString($data['delivery_id'])
            ),
            'password_reset' => new self(
                PasswordResetGrantId::fromString($data['id']),
                $data['revision'],
                PasswordResetDeliveryId::fromString($data['delivery_id'])
            ),
            'email_change' => new self(
                EmailChangeGrantId::fromString($data['id']),
                $data['revision'],
                EmailChangeDeliveryId::fromString($data['delivery_id'])
            ),
            default => throw new DomainException('Audit grant kind is unsupported.')
        };
    }

    /**
     * Validates the actual retained predecessor revision when a different generation succeeds it
     *
     * @throws DomainException When a replacement omits or invents its retained predecessor
     */
    public function assertReplacement(self $after, ?self $replacedPredecessor): void
    {
        if ($this->id->equals($after->id)) {
            if ($replacedPredecessor !== null) {
                throw new DomainException('An in-place grant outcome has no replaced predecessor.');
            }

            return;
        }

        if (
            $replacedPredecessor === null || $this->id::class !== $after->id::class
            || !$this->id->equals($replacedPredecessor->id)
            || !$this->deliveryId->equals($replacedPredecessor->deliveryId)
            || $replacedPredecessor->revision < $this->revision
            || ($replacedPredecessor->revision !== $this->revision
                && ($this->revision === PHP_INT_MAX || $replacedPredecessor->revision !== $this->revision + 1))
        ) {
            throw new DomainException('A grant replacement requires its actual retained predecessor revision.');
        }
    }

    /**
     * Returns the precise grant identity
     */
    public function getId(): ActivationGrantId|PasswordResetGrantId|EmailChangeGrantId
    {
        return $this->id;
    }

    /**
     * Returns the grant revision and typed delivery relationship
     *
     * @phpstan-return GrantStatePayload
     */
    public function toArray(): array
    {
        $kind = match (true) {
            $this->id instanceof ActivationGrantId => 'activation',
            $this->id instanceof PasswordResetGrantId => 'password_reset',
            $this->id instanceof EmailChangeGrantId => 'email_change'
        };

        return [
            'kind'        => $kind,
            'id'          => $this->id->toString(),
            'revision'    => $this->revision,
            'delivery_id' => $this->deliveryId->toString()
        ];
    }
}
