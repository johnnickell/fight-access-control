<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Authorizes administration of another user's email-change journey.
 */
interface EmailChangeAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot administer the target user's email change.
     *
     * @throws EmailChangeAdministrationAuthorizationException When the actor is not authorized
     */
    public function assertCanManageEmailChange(UserId $actorId, UserId $userId): void;
}
