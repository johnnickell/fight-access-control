<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Interface RegistrationRecoveryAuthorization
 *
 * Binds trusted current administrator authority to the exact requested actor and target.
 * Caller-supplied identity, email or delivery possession never grants authority. Implementations
 * authenticate the actor from trusted execution context, reject mismatches and check target-scoped
 * recovery permission. Hold authority valid through the enclosing transaction's completion using
 * the consumer's authorization fence; do not rely on an earlier route or cached discovery decision.
 */
interface RegistrationRecoveryAuthorization
{
    /**
     * Rejects recovery without trusted administrator authority for this exact target
     *
     * The handler translates only this port's explicit denial to Application PermissionDenied.
     * Unknown identity, configuration and provider failures must propagate as internal faults,
     * never be fabricated as authorization denial.
     *
     * @throws RegistrationRecoveryAuthorizationException When the actor or target is unauthorized
     */
    public function assertCanRecoverRegistration(UserId $actorId, UserId $userId): void;
}
