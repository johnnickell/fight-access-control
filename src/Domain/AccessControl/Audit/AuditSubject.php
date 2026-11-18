<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditSubject
 *
 * Preserves the kind of a supported primary package subject without retaining its state.
 *
 * @phpstan-type SubjectPayload array{kind: 'user'|'agent'|'role'|'permission'|'feature', id: string}
 */
final readonly class AuditSubject implements Arrayable
{
    /**
     * Constructs AuditSubject
     */
    public function __construct(private UserId|AgentId|RoleId|PermissionId|FeatureId $id)
    {
    }

    /**
     * Creates a supported subject from its complete persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When the representation has unsupported fields or kinds
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2
            || !isset($data['kind'], $data['id'])
            || !is_string($data['kind'])
            || !is_string($data['id'])
        ) {
            throw new DomainException('Audit subject requires exactly a kind and identity.');
        }

        return new self(match ($data['kind']) {
            'user' => UserId::fromString($data['id']),
            'agent' => AgentId::fromString($data['id']),
            'role' => RoleId::fromString($data['id']),
            'permission' => PermissionId::fromString($data['id']),
            'feature' => FeatureId::fromString($data['id']),
            default => throw new DomainException('Audit subject kind is unsupported.')
        });
    }

    /**
     * Returns the precise subject identity
     */
    public function getId(): UserId|AgentId|RoleId|PermissionId|FeatureId
    {
        return $this->id;
    }

    /**
     * Returns the complete subject representation without entity or payload capture
     *
     * @phpstan-return SubjectPayload
     */
    public function toArray(): array
    {
        $kind = match (true) {
            $this->id instanceof UserId => 'user',
            $this->id instanceof AgentId => 'agent',
            $this->id instanceof RoleId => 'role',
            $this->id instanceof PermissionId => 'permission',
            $this->id instanceof FeatureId => 'feature'
        };

        return ['kind' => $kind, 'id' => $this->id->toString()];
    }
}
