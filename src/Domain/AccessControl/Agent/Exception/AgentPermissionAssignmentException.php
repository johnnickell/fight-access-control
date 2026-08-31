<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports an invalid Agent direct-Permission assignment operation.
 */
class AgentPermissionAssignmentException extends DomainException
{
}
