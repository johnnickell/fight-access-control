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
 * Records a complete Agent Permission assignment result after durable commit.
 */
final readonly class AgentPermissionsReplaced implements Event
{
    /**
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

        return new static(
            UserId::fromString((string) $data['actor_id']),
            AgentId::fromString((string) $data['agent_id']),
            array_map(
                static fn(mixed $id): PermissionId => PermissionId::fromString((string) $id),
                (array) $data['permission_ids']
            ),
            (int) $data['permission_assignment_revision'],
            new DateTimeImmutable((string) $data['replaced_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'agent_id' => $this->agentId->toString(),
            'permission_ids' => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->permissionIds
            ),
            'permission_assignment_revision' => $this->permissionAssignmentRevision,
            'replaced_at' => $this->replacedAt->format(DATE_ATOM),
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
     * Returns the changed Agent.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the resulting Permission identities.
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the resulting assignment revision.
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }

    /**
     * Returns the replacement time.
     */
    public function getReplacedAt(): DateTimeImmutable
    {
        return $this->replacedAt;
    }
}
