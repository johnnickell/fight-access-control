<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\UserRoleAssignmentAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserRoleAssignmentAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Throwable;

final class FixedUserRoleAssignmentAdministrationAuthorization implements UserRoleAssignmentAdministrationAuthorization
{
    private int $calls = 0;

    private ?UserId $lastActorId = null;

    public function __construct(
        private readonly bool $authorized,
        private readonly ?Throwable $failure = null
    ) {
    }

    public function assertCanManageUserRoleAssignments(UserId $actorId): void
    {
        ++$this->calls;
        $this->lastActorId = $actorId;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if (!$this->authorized) {
            throw new UserRoleAssignmentAuthorizationException(
                'User role-assignment administration is not authorized.'
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
}
