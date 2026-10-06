<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentOperationScope
 *
 * Identifies an originating authority scope without granting authorization.
 */
final readonly class AgentOperationScope
{
    /**
     * Constructs AgentOperationScope
     */
    public function __construct(private string $namespace, private string $callerType, private string $callerId)
    {
        foreach ([[$namespace, 64], [$callerType, 32], [$callerId, 128]] as [$value, $maximum]) {
            if (preg_match('/\A[A-Za-z0-9_.:-]{1,'.$maximum.'}\z/D', $value) !== 1) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }
        }
    }

    /**
     * Returns the trusted consumer namespace
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Returns the originating principal type
     */
    public function getCallerType(): string
    {
        return $this->callerType;
    }

    /**
     * Returns the originating stable principal identity
     */
    public function getCallerId(): string
    {
        return $this->callerId;
    }

    /**
     * Returns an unambiguous persistence identity without delimiter collisions
     */
    public function toString(): string
    {
        return json_encode([$this->namespace, $this->callerType, $this->callerId], JSON_THROW_ON_ERROR);
    }
}
