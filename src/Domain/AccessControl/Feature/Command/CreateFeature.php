<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class CreateFeature
 *
 * Requests manual OFF creation with an existing testing Permission.
 */
final readonly class CreateFeature implements Command
{
    /**
     * Constructs CreateFeature
     */
    public function __construct(
        private FeatureName $name,
        private PermissionId $permissionId,
        private UserId|AgentId|SystemId|AnonymousId|null $actorId = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['name', 'permission_id'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid required string "%s" in data array', $key));
            }
        }

        $actorId = null;
        if (($data['actor_kind'] ?? null) !== null || ($data['actor_id'] ?? null) !== null) {
            if (!is_string($data['actor_kind'] ?? null) || !is_string($data['actor_id'] ?? null)) {
                throw new DomainException('Actor provenance requires both kind and identity.');
            }

            $kind = AuditActorKind::tryFrom($data['actor_kind']) ?? throw new DomainException('Invalid actor kind.');
            $actorId = $kind->idFromString($data['actor_id']);
        }

        return new self(
            FeatureName::fromString($data['name']),
            PermissionId::fromString($data['permission_id']),
            $actorId
        );
    }

    /**
     * Returns the canonical command payload
     *
     * @return array{name: string, permission_id: string, actor_kind: ?string, actor_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'name'          => $this->name->toString(),
            'permission_id' => $this->permissionId->toString(),
            'actor_kind'    => $this->actorId === null ? null : AuditActorKind::forId($this->actorId)->value,
            'actor_id'      => $this->actorId?->toString()
        ];
    }

    /**
     * Returns explicit originating provenance without granting authority
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId|null
    {
        return $this->actorId;
    }

    /**
     * Returns the new Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }

    /**
     * Returns the chosen testing Permission identity
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }
}
