<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ProvisionFeatures
 *
 * Requests creation of missing candidate-code Features, not activation or runtime availability.
 */
final readonly class ProvisionFeatures implements Command
{
    /**
     * Constructs ProvisionFeatures
     *
     * Keep raw configuration until creation is needed; unused invalid configuration must not reject a no-op pass.
     */
    public function __construct(
        private ?string $defaultPermissionName,
        private UserId|AgentId|SystemId|AnonymousId|null $actorId = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('default_permission_name', $data)) {
            throw new DomainException('Missing required key "default_permission_name" in data array');
        }

        $name = $data['default_permission_name'];
        if ($name !== null && !is_string($name)) {
            throw new DomainException('The default Permission configuration must be a string or null.');
        }

        if (!array_key_exists('actor_kind', $data) || !array_key_exists('actor_id', $data)) {
            throw new DomainException('Provisioning requires explicit nullable actor fields.');
        }

        $actorId = null;
        if ($data['actor_kind'] !== null || $data['actor_id'] !== null) {
            if (!is_string($data['actor_kind']) || !is_string($data['actor_id'])) {
                throw new DomainException('Provisioning actor requires both a kind and identity, or both null.');
            }

            $kind = AuditActorKind::tryFrom($data['actor_kind']);
            if ($kind === null) {
                throw new DomainException('Unsupported actor kind.');
            }

            $actorId = $kind->idFromString($data['actor_id']);
        }

        return new self($name, $actorId);
    }

    /**
     * Returns the canonical command payload
     *
     * @return array{default_permission_name: string|null, actor_kind: string|null, actor_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'default_permission_name' => $this->defaultPermissionName,
            'actor_kind'              => $this->actorId === null ? null : AuditActorKind::forId($this->actorId)->value,
            'actor_id'                => $this->actorId?->toString()
        ];
    }

    /**
     * Returns the consumer-supplied configuration without reading the process environment
     */
    public function getDefaultPermissionName(): ?string
    {
        return $this->defaultPermissionName;
    }

    /**
     * Returns explicit originating provenance without granting provisioning authority
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId|null
    {
        return $this->actorId;
    }
}
