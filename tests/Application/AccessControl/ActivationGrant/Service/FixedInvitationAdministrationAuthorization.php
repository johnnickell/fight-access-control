<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\InvitationAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

final class FixedInvitationAdministrationAuthorization implements InvitationAdministrationAuthorization
{
    private int $calls = 0;

    private ?UserId $lastActorId = null;

    private ?UserId $lastUserId = null;

    public function __construct(private readonly bool $authorized)
    {
    }

    public function assertCanCorrectInvitation(UserId $actorId, UserId $userId): void
    {
        ++$this->calls;
        $this->lastActorId = $actorId;
        $this->lastUserId = $userId;

        if (!$this->authorized) {
            throw new InvitationAdministrationAuthorizationException(
                'Invitation administration is not authorized.'
            );
        }
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function lastActorId(): ?UserId
    {
        return $this->lastActorId;
    }

    public function lastUserId(): ?UserId
    {
        return $this->lastUserId;
    }
}
