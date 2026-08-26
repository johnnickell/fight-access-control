<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentPermissionAdministrationAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Throwable;

final class FixedAgentPermissionAdministrationAuthorization implements AgentPermissionAdministrationAuthorization
{
    private int $calls = 0;

    private ?UserId $lastActorId = null;

    public function __construct(
        private readonly bool $authorized,
        private readonly ?Throwable $failure = null
    ) {
    }

    public function assertCanManageAgentPermissions(UserId $actorId): void
    {
        ++$this->calls;
        $this->lastActorId = $actorId;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if (!$this->authorized) {
            throw new AgentPermissionAssignmentAuthorizationException(
                'Agent Permission-assignment administration is not authorized.'
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
