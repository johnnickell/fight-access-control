<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class AgentPermissionsReplaced
 *
 * Records a complete Agent Permission assignment result after durable commit.
 */
final readonly class AgentPermissionsReplaced implements Event
{
    /**
     * Constructs AgentPermissionsReplaced
     *
     * Creates the complete Agent Permission replacement event.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public function __construct(
        private UserId $actorId,
        private AgentId $agentId,
        private array $permissionIds,
        private int $permissionAssignmentRevision,
        private DateTimeImmutable $replacedAt
    ) {
        if ($permissionAssignmentRevision < 1) {
            throw new DomainException('Agent Permission events require a positive revision.');
        }

        self::assertPermissionIds($permissionIds);
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (
            ['actor_id', 'agent_id', 'permission_ids', 'permission_assignment_revision', 'replaced_at'] as $key
        ) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        if (
            !is_string($data['actor_id']) || !is_string($data['agent_id']) || !is_string($data['replaced_at'])
            || !is_int($data['permission_assignment_revision'])
            || !is_array($data['permission_ids']) || !array_is_list($data['permission_ids'])
        ) {
            throw new DomainException('Agent Permission events require string fields, an integer and a list.');
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
            $permissionIds,
            $data['permission_assignment_revision'],
            AgentEventTime::fromString($data['replaced_at'])
        );
    }

    /**
     * Returns the canonical event payload
     *
     * @return array{
     *     actor_id: string, agent_id: string, permission_ids: list<string>,
     *     permission_assignment_revision: int, replaced_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'                       => $this->actorId->toString(),
            'agent_id'                       => $this->agentId->toString(),
            'permission_ids'                 => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->permissionIds
            ),
            'permission_assignment_revision' => $this->permissionAssignmentRevision,
            'replaced_at'                    => $this->replacedAt->format(DATE_ATOM)
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
     * Returns the changed Agent
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the resulting Permission identities
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the resulting assignment revision
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }

    /**
     * Returns the replacement time
     */
    public function getReplacedAt(): DateTimeImmutable
    {
        return $this->replacedAt;
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
