<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Domain\Type\Arrayable;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentOperationView
 *
 * Separates original issuance from recorded disposition without retaining material or implying use authority.
 *
 * @phpstan-import-type KeyPayload from AgentOperationKey
 * @phpstan-import-type IssuancePayload from AgentIssuance
 * @phpstan-type Confirmation array{
 *     canonical_version: int, issuance: AgentIssuance,
 *     delivery_disposition: AgentDeliveryDisposition, credential_disposition: AgentCredentialDisposition
 * }
 * @phpstan-type ViewPayload array{
 *     key: KeyPayload, issuance_outcome: 'indeterminate', canonical_version: null, issuance: null,
 *     delivery_disposition: null, credential_disposition: null
 * }|array{
 *     key: KeyPayload, issuance_outcome: 'confirmed', canonical_version: int, issuance: IssuancePayload,
 *     delivery_disposition: value-of<AgentDeliveryDisposition>,
 *     credential_disposition: value-of<AgentCredentialDisposition>
 * }
 */
final readonly class AgentOperationView implements Arrayable
{
    /**
     * Constructs AgentOperationView
     *
     * @phpstan-param Confirmation|null $confirmation
     */
    private function __construct(private AgentOperationKey $key, private ?array $confirmation)
    {
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
        return new self($issuance->getKey(), [
            'canonical_version'      => $canonicalVersion,
            'issuance'               => $issuance,
            'delivery_disposition'   => $deliveryDisposition,
            'credential_disposition' => $credentialDisposition
        ]);
    }

    /**
     * Creates a safe projection without making the aggregate know its public read representation
     *
     * Consumer read repositories should project stored metadata directly, without loading material-bearing roots.
     * This mapping also serves already loaded snapshots; it reads no material, attempt, receipt or request content.
     */
    public static function fromOperation(#[SensitiveParameter] AgentCredentialOperation $operation): self
    {
        return self::confirmed(
            $operation->getCanonicalVersion(),
            $operation->getIssuance(),
            $operation->getDeliveryDisposition(),
            $operation->getCredentialDisposition()
        );
    }

    /**
     * Creates an unknown outcome without inferring rollback or permission to issue again
     */
    public static function indeterminate(AgentOperationKey $key): self
    {
        return new self($key, null);
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
     * Validates correlation and the supported persisted marker only after current authorization
     */
    public function assertReadable(AgentOperationKey $key, AgentCredentialDestination $destination): void
    {
        if (
            $this->key->toString() !== $key->toString()
            || ($this->confirmation !== null
                && $this->confirmation['issuance']->getDestination()->toArray() !== $destination->toArray())
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        if ($this->confirmation !== null) {
            AgentOperationCanonicalization::assertSupported($this->confirmation['canonical_version']);
        }
    }

    /**
     * Returns whether authoritative storage confirms original issuance
     */
    public function isConfirmed(): bool
    {
        return $this->confirmation !== null;
    }

    /**
     * Returns the original scoped operation key
     */
    public function getKey(): AgentOperationKey
    {
        return $this->key;
    }

    /**
     * Returns the retained request contract marker
     */
    public function getCanonicalVersion(): ?int
    {
        return $this->confirmation['canonical_version'] ?? null;
    }

    /**
     * Returns original issuance rather than any successor's metadata
     */
    public function getIssuance(): ?AgentIssuance
    {
        return $this->confirmation['issuance'] ?? null;
    }

    /**
     * Returns delivery disposition independently of issuance and credential authority
     */
    public function getDeliveryDisposition(): ?AgentDeliveryDisposition
    {
        return $this->confirmation['delivery_disposition'] ?? null;
    }

    /**
     * Returns the disposition of the original credential without granting authority
     */
    public function getCredentialDisposition(): ?AgentCredentialDisposition
    {
        return $this->confirmation['credential_disposition'] ?? null;
    }

    /**
     * Returns only safe metadata without request digests, receipts, provider details or secret handles
     *
     * @phpstan-return ViewPayload
     */
    public function toArray(): array
    {
        if ($this->confirmation === null) {
            return [
                'key'                    => $this->key->toArray(),
                'issuance_outcome'       => 'indeterminate',
                'canonical_version'      => null,
                'issuance'               => null,
                'delivery_disposition'   => null,
                'credential_disposition' => null
            ];
        }

        return [
            'key'                    => $this->key->toArray(),
            'issuance_outcome'       => 'confirmed',
            'canonical_version'      => $this->confirmation['canonical_version'],
            'issuance'               => $this->confirmation['issuance']->toArray(),
            'delivery_disposition'   => $this->confirmation['delivery_disposition']->value,
            'credential_disposition' => $this->confirmation['credential_disposition']->value
        ];
    }
}
