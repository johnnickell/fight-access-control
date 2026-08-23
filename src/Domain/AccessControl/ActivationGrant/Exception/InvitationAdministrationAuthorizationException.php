<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports denied administrative access to an invitation.
 */
final class InvitationAdministrationAuthorizationException extends DomainException
{
}
