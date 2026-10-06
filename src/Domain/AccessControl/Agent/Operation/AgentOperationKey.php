<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\Common\Domain\Type\Arrayable;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentOperationKey
 */
final readonly class AgentOperationKey implements Arrayable
{
    /**
     * Constructs AgentOperationKey
     */
    public function __construct(private AgentOperationScope $scope, private AgentOperationId $id)
    {
    }

    /**
     * Creates a bounded scoped identifier without granting authority
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        try {
            foreach (['namespace', 'caller_type', 'caller_id', 'operation_id'] as $field) {
                if (!isset($data[$field]) || !is_string($data[$field])) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
                }
            }

            return new self(
                new AgentOperationScope($data['namespace'], $data['caller_type'], $data['caller_id']),
                AgentOperationId::fromString($data['operation_id'])
            );
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the canonical safe scoped identifier
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'namespace'    => $this->scope->getNamespace(),
            'caller_type'  => $this->scope->getCallerType(),
            'caller_id'    => $this->scope->getCallerId(),
            'operation_id' => $this->id->toString()
        ];
    }

    /**
     * Returns the original scope independently of any delegated invoker
     */
    public function getScope(): AgentOperationScope
    {
        return $this->scope;
    }

    /**
     * Returns the caller-retained operation identity
     */
    public function getId(): AgentOperationId
    {
        return $this->id;
    }

    /**
     * Returns the unambiguous scoped persistence key
     */
    public function toString(): string
    {
        return $this->scope->toString().':'.$this->id->toString();
    }
}
