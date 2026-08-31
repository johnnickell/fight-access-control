<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Throwable;

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

        try {
            return new self(
                PermissionId::fromString((string) $data['id']),
                PermissionName::fromString((string) $data['name']),
                PermissionTier::from((string) $data['tier'])
            );
        } catch (Throwable $throwable) {
            throw new ManagedPolicyDefinitionException(
                'Invalid managed permission definition: '.$throwable->getMessage(),
                0,
                $throwable
            );
        }
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
