<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

/**
 * Class AgentPermissionRevisionMismatchException
 *
 * Indicates that the supplied assignment revision does not match current authority
 */
final class AgentPermissionRevisionMismatchException extends AgentPermissionAssignmentException
{
}
