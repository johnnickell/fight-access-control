<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests replacing an Agent's complete direct-Permission assignment set.
 */
final readonly class ReplaceAgentPermissions implements Command
{
    /**
     * Creates the complete Agent Permission replacement command.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public function __construct(
        private UserId $actorId,
        private AgentId $agentId,
        private int $expectedPermissionAssignmentRevision,
        private array $permissionIds
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'agent_id', 'expected_permission_assignment_revision', 'permission_ids'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            AgentId::fromString((string) $data['agent_id']),
            (int) $data['expected_permission_assignment_revision'],
            array_map(
                static fn(mixed $id): PermissionId => PermissionId::fromString((string) $id),
                (array) $data['permission_ids']
            )
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'agent_id' => $this->agentId->toString(),
            'expected_permission_assignment_revision' => $this->expectedPermissionAssignmentRevision,
            'permission_ids' => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->permissionIds
            ),
        ];
    }

    /**
     * Returns the administrative actor.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target Agent.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the expected assignment revision.
     */
    public function getExpectedPermissionAssignmentRevision(): int
    {
        return $this->expectedPermissionAssignmentRevision;
    }

    /**
     * Returns the requested complete Permission identity set.
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }
}
