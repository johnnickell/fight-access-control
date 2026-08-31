<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\InvitationAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Authorizes administrative correction of a pending invitation.
 */
interface InvitationAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot correct the user's invitation.
     *
     * @throws InvitationAdministrationAuthorizationException When the actor is not authorized
     */
    public function assertCanCorrectInvitation(UserId $actorId, UserId $userId): void;
}
