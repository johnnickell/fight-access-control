<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Domain\AccessControl\User\Exception\UserRoleAssignmentAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Authorizes administrative User role-assignment changes.
 */
interface UserRoleAssignmentAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot manage User role assignments.
     *
     * @throws UserRoleAssignmentAuthorizationException When the actor is not authorized
     */
    public function assertCanManageUserRoleAssignments(UserId $actorId): void;
}
