<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\Service;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Authorizes administrative access to another user's refresh sessions.
 */
interface SessionAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot manage the user's refresh sessions.
     *
     * @throws SessionAdministrationAuthorizationException When the actor is not authorized
     */
    public function assertCanManageSessions(UserId $actorId, UserId $userId): void;
}
