<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\InvitationAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Interface InvitationAdministrationAuthorization
 *
 * Binds trusted current administrator authority to the exact requested actor and target.
 * Supplied identities and private-invitation origin grant no permission. Implementations validate
 * trusted execution identity and target-scoped authority under the consumer's authorization fence
 * through transaction completion; earlier route or cached discovery decisions are insufficient.
 */
interface InvitationAdministrationAuthorization
{
    /**
     * Rejects an actor who cannot correct the user's invitation
     *
     * The handler translates only this port's explicit denial to Application PermissionDenied.
     * Unknown identity, configuration and provider failures must propagate as internal faults,
     * never be fabricated as authorization denial.
     *
     * @throws InvitationAdministrationAuthorizationException When the actor or target is unauthorized
     */
    public function assertCanCorrectInvitation(UserId $actorId, UserId $userId): void;
}
