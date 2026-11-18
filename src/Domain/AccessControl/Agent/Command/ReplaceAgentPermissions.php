<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ReplaceAgentPermissions
 *
 * Requests replacing an Agent's complete direct-Permission assignment set.
 */
final readonly class ReplaceAgentPermissions implements Command
{
    /**
     * Constructs ReplaceAgentPermissions
     *
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
        if ($expectedPermissionAssignmentRevision < 1) {
            throw new DomainException('Agent Permission replacement requires a positive revision.');
        }

        self::assertPermissionIds($permissionIds);
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'agent_id', 'expected_permission_assignment_revision', 'permission_ids'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        if (
            !is_string($data['actor_id']) || !is_string($data['agent_id'])
            || !is_int($data['expected_permission_assignment_revision'])
            || !is_array($data['permission_ids']) || !array_is_list($data['permission_ids'])
        ) {
            throw new DomainException('Agent Permission replacement requires string IDs, an integer and a list.');
        }

        $permissionIds = [];
        foreach ($data['permission_ids'] as $id) {
            if (!is_string($id)) {
                throw new DomainException('Agent Permission identities must be strings.');
            }

            $permissionIds[] = PermissionId::fromString($id);
        }

        return new static(
            UserId::fromString($data['actor_id']),
            AgentId::fromString($data['agent_id']),
            $data['expected_permission_assignment_revision'],
            $permissionIds
        );
    }

    /**
     * Returns the canonical command payload
     *
     * @return array{
     *     actor_id: string, agent_id: string,
     *     expected_permission_assignment_revision: int, permission_ids: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'                                => $this->actorId->toString(),
            'agent_id'                                => $this->agentId->toString(),
            'expected_permission_assignment_revision' => $this->expectedPermissionAssignmentRevision,
            'permission_ids'                          => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->permissionIds
            )
        ];
    }

    /**
     * Returns the administrative actor
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target Agent
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the expected assignment revision
     */
    public function getExpectedPermissionAssignmentRevision(): int
    {
        return $this->expectedPermissionAssignmentRevision;
    }

    /**
     * Returns the requested complete Permission identity set
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Validates the native array boundary without relying on erased collection annotations
     *
     * @param array<array-key, mixed> $permissionIds
     */
    private static function assertPermissionIds(array $permissionIds): void
    {
        if (
            !array_is_list($permissionIds)
            || !array_all($permissionIds, static fn(mixed $id): bool => $id instanceof PermissionId)
        ) {
            throw new DomainException('Agent Permission identities must be a list of PermissionId values.');
        }
    }
}
