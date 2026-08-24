<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\Exception\ManagedPolicyDefinitionException;

/**
 * Defines one version-controlled managed permission.
 */
final readonly class ManagedPermissionDefinition
{
    /**
     * Constructs a managed permission definition.
     */
    public function __construct(
        private PermissionId $id,
        private PermissionName $name,
        private PermissionTier $tier
    ) {
    }

    /**
     * Creates a definition from its serialized representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'tier'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManagedPolicyDefinitionException(sprintf(
                    'Missing required managed permission key "%s".',
                    $key
                ));
            }
        }

        return new self(
            PermissionId::fromString((string) $data['id']),
            PermissionName::fromString((string) $data['name']),
            PermissionTier::from((string) $data['tier'])
        );
    }

    /**
     * Returns the serialized definition.
     *
     * @return array{id: string, name: string, tier: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'name' => $this->name->toString(),
            'tier' => $this->tier->value,
        ];
    }

    /**
     * Returns the stable permission identifier.
     */
    public function getId(): PermissionId
    {
        return $this->id;
    }

    /**
     * Returns the canonical permission name.
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }

    /**
     * Returns the permission grant tier.
     */
    public function getTier(): PermissionTier
    {
        return $this->tier;
    }
}
