<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\Common\Domain\Type\Arrayable;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentOperationView
 *
 * Separates original issuance from recorded disposition without retaining material or implying use authority.
 */
final readonly class AgentOperationView implements Arrayable
{
    /**
     * Constructs AgentOperationView
     */
    private function __construct(
        private AgentOperationKey $key,
        private ?int $canonicalVersion,
        private ?AgentIssuance $issuance,
        private ?AgentDeliveryDisposition $deliveryDisposition,
        private ?AgentCredentialDisposition $credentialDisposition
    ) {
    }

    /**
     * Creates a coherent safe projection of confirmed persisted issuance and current recorded disposition
     *
     * Retain unknown versions during hydration; the read boundary rejects them before disclosure.
     */
    public static function confirmed(
        int $canonicalVersion,
        AgentIssuance $issuance,
        AgentDeliveryDisposition $deliveryDisposition,
        AgentCredentialDisposition $credentialDisposition
    ): self {
        return new self(
            $issuance->getKey(),
            $canonicalVersion,
            $issuance,
            $deliveryDisposition,
            $credentialDisposition
        );
    }

    /**
     * Creates an unknown outcome without inferring rollback or permission to issue again
     */
    public static function indeterminate(AgentOperationKey $key): self
    {
        return new self($key, null, null, null, null);
    }

    /**
     * Creates a safe result from its canonical representation without accepting contradictory outcomes
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        try {
            $required = ['key', 'issuance_outcome', 'canonical_version', 'issuance',
                'delivery_disposition', 'credential_disposition'];
            foreach ($required as $field) {
                if (!array_key_exists($field, $data)) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }
            }

            if (!is_array($data['key'])) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            $key = AgentOperationKey::fromArray($data['key']);
            if ($data['issuance_outcome'] === 'indeterminate') {
                if (
                    $data['canonical_version'] !== null || $data['issuance'] !== null
                    || $data['delivery_disposition'] !== null || $data['credential_disposition'] !== null
                ) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }

                return self::indeterminate($key);
            }

            if (
                $data['issuance_outcome'] !== 'confirmed' || !is_int($data['canonical_version'])
                || !is_array($data['issuance']) || !is_string($data['delivery_disposition'])
                || !is_string($data['credential_disposition'])
            ) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            $issuance = AgentIssuance::fromArray($data['issuance']);
            if ($issuance->getKey()->toString() !== $key->toString()) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            return self::confirmed(
                $data['canonical_version'],
                $issuance,
                AgentDeliveryDisposition::from($data['delivery_disposition']),
                AgentCredentialDisposition::from($data['credential_disposition'])
            );
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Validates correlation and historical interpretation only after current authorization
     */
    public function assertReadable(AgentOperationKey $key, AgentCredentialDestination $destination): void
    {
        if (
            $this->key->toString() !== $key->toString()
            || ($this->issuance !== null && $this->issuance->getDestination()->toArray() !== $destination->toArray())
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        if ($this->issuance !== null) {
            AgentOperationCanonicalization::assertSupported($this->canonicalVersion ?? 0);
        }
    }

    /**
     * Returns whether authoritative storage confirms original issuance
     */
    public function isConfirmed(): bool
    {
        return $this->issuance !== null;
    }

    /**
     * Returns the original scoped operation key
     */
    public function getKey(): AgentOperationKey
    {
        return $this->key;
    }

    /**
     * Returns the retained request version without selecting a new canonicalizer
     */
    public function getCanonicalVersion(): ?int
    {
        return $this->canonicalVersion;
    }

    /**
     * Returns original issuance rather than any successor's metadata
     */
    public function getIssuance(): ?AgentIssuance
    {
        return $this->issuance;
    }

    /**
     * Returns delivery disposition independently of issuance and credential authority
     */
    public function getDeliveryDisposition(): ?AgentDeliveryDisposition
    {
        return $this->deliveryDisposition;
    }

    /**
     * Returns the disposition of the original credential without granting authority
     */
    public function getCredentialDisposition(): ?AgentCredentialDisposition
    {
        return $this->credentialDisposition;
    }

    /**
     * Returns only safe metadata without request digests, receipts, provider details or secret handles
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $outcome = 'indeterminate';
        if ($this->isConfirmed()) {
            $outcome = 'confirmed';
        }

        return [
            'key'                    => $this->key->toArray(),
            'issuance_outcome'       => $outcome,
            'canonical_version'      => $this->canonicalVersion,
            'issuance'               => $this->issuance?->toArray(),
            'delivery_disposition'   => $this->deliveryDisposition?->value,
            'credential_disposition' => $this->credentialDisposition?->value
        ];
    }
}
