<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Tool\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Mcp\Tool\McpToolFailureMap;

/**
 * Class AgentProfileToolFailures
 *
 * Binds expected operation failures to safe constants; unknown faults remain with Common's central boundary.
 */
final class AgentProfileToolFailures
{
    /**
     * Creates the expected failure bindings for the protected profile Tool composition
     */
    public static function create(): McpToolFailureMap
    {
        return new McpToolFailureMap([
            AgentNameException::class              => 'The Agent name is invalid.',
            AgentOperationRejectedException::class => 'The Agent profile operation could not be completed.',
            InvalidInput::class                    => 'The Agent name is invalid.',
            ResourceNotFound::class                => 'The Agent profile is unavailable.',
            StateConflict::class                   => 'The Agent profile operation could not be completed.'
        ]);
    }
}
