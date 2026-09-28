<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

/**
 * Class AgentOperationKey
 */
final readonly class AgentOperationKey
{
    /**
     * Constructs AgentOperationKey
     */
    public function __construct(private AgentOperationScope $scope, private AgentOperationId $id)
    {
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
