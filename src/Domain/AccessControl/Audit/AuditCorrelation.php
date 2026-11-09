<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\MessageId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditCorrelation
 *
 * Retains actual message and scoped operation identity, not messages or authorization claims.
 *
 * @phpstan-import-type KeyPayload from AgentOperationKey
 * @phpstan-type CorrelationPayload array{message_id: ?string, operation_key: ?KeyPayload}
 */
final readonly class AuditCorrelation implements Arrayable
{
    /**
     * Constructs AuditCorrelation
     *
     * @throws DomainException When neither actual correlation is supplied
     */
    public function __construct(private ?MessageId $messageId, private ?AgentOperationKey $operationKey)
    {
        if ($messageId === null && $operationKey === null) {
            throw new DomainException('Audit correlation requires a message or scoped operation identity.');
        }
    }

    /**
     * Creates correlation from its complete persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When correlation fields have an invalid shape
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2
            || !array_key_exists('message_id', $data)
            || !array_key_exists('operation_key', $data)
            || ($data['message_id'] !== null && !is_string($data['message_id']))
            || ($data['operation_key'] !== null && !is_array($data['operation_key']))
        ) {
            throw new DomainException('Audit correlation requires explicit message and operation fields.');
        }

        $operationKey = null;
        if ($data['operation_key'] !== null) {
            $key = $data['operation_key'];
            if (
                count($key) !== 4
                || !isset($key['namespace'], $key['caller_type'], $key['caller_id'], $key['operation_id'])
                || !is_string($key['namespace'])
                || !is_string($key['caller_type'])
                || !is_string($key['caller_id'])
                || !is_string($key['operation_id'])
            ) {
                throw new DomainException('Audit operation correlation requires a complete scoped key.');
            }

            $operationKey = AgentOperationKey::fromArray($key);
        }

        return new self(
            $data['message_id'] === null ? null : MessageId::fromString($data['message_id']),
            $operationKey
        );
    }

    /**
     * Returns the original incoming message identity when available
     */
    public function getMessageId(): ?MessageId
    {
        return $this->messageId;
    }

    /**
     * Returns the complete originating operation key when available
     */
    public function getOperationKey(): ?AgentOperationKey
    {
        return $this->operationKey;
    }

    /**
     * Returns correlation without collapsing an operation key to its unscoped UUID
     *
     * @phpstan-return CorrelationPayload
     */
    public function toArray(): array
    {
        return [
            'message_id'    => $this->messageId?->toString(),
            'operation_key' => $this->operationKey?->toArray()
        ];
    }
}
