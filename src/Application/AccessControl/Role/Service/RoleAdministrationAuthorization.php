<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\Service;

use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Authorizes administrative custom-role management.
 */
interface RoleAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot manage custom roles.
     *
     * @throws RoleAdministrationAuthorizationException When the actor is not authorized
     */
    public function assertCanManageRoles(UserId $actorId): void;
}
