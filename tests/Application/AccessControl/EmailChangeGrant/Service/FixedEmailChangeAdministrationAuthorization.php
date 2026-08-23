<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

final class FixedEmailChangeAdministrationAuthorization implements EmailChangeAdministrationAuthorization
{
    private int $calls = 0;

    private ?UserId $lastActorId = null;

    private ?UserId $lastUserId = null;

    public function __construct(private readonly bool $authorized)
    {
    }

    public function assertCanManageEmailChange(UserId $actorId, UserId $userId): void
    {
        ++$this->calls;
        $this->lastActorId = $actorId;
        $this->lastUserId = $userId;

        if (!$this->authorized) {
            throw new EmailChangeAdministrationAuthorizationException(
                'Email-change administration is not authorized.'
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
