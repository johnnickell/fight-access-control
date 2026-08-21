<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\SessionAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

final class FixedSessionAdministrationAuthorization implements SessionAdministrationAuthorization
{
    private int $calls = 0;

    private ?UserId $lastActorId = null;

    private ?UserId $lastUserId = null;

    public function __construct(private readonly bool $authorized)
    {
    }

    public function assertCanManageSessions(UserId $actorId, UserId $userId): void
    {
        ++$this->calls;
        $this->lastActorId = $actorId;
        $this->lastUserId = $userId;

        if (!$this->authorized) {
            throw new SessionAdministrationAuthorizationException('Session administration is not authorized.');
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
