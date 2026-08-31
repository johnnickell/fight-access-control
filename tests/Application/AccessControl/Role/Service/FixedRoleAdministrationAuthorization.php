<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Service;

use Fight\AccessControl\Application\AccessControl\Role\Service\RoleAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

final class FixedRoleAdministrationAuthorization implements RoleAdministrationAuthorization
{
    private int $calls = 0;

    private ?UserId $lastActorId = null;

    public function __construct(private readonly bool $authorized)
    {
    }

    public function assertCanManageRoles(UserId $actorId): void
    {
        ++$this->calls;
        $this->lastActorId = $actorId;

        if (!$this->authorized) {
            throw new RoleAdministrationAuthorizationException('Role administration is not authorized.');
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
}
