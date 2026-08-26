<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Authorizes administrative Agent Permission-assignment changes.
 */
interface AgentPermissionAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot manage Agent Permission assignments.
     *
     * @throws AgentPermissionAssignmentAuthorizationException When the actor is not authorized
     */
    public function assertCanManageAgentPermissions(UserId $actorId): void;
}
