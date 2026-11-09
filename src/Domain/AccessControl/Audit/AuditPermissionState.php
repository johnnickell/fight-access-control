<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditPermissionState
 *
 * Preserves admitted definition values without inventing a Permission revision.
 *
 * @phpstan-type PermissionStatePayload array{name: string, tier: string, managed: bool}
 */
final readonly class AuditPermissionState implements Arrayable
{
    /**
     * Constructs AuditPermissionState
     */
    public function __construct(private PermissionName $name, private PermissionTier $tier, private bool $managed)
    {
        if (!$managed && $tier !== PermissionTier::ADMIN_SAFE) {
            throw new DomainException('An audited custom Permission cannot have a protected tier.');
        }
    }

    /**
     * Creates admitted definition facts from authoritative state
     */
    public static function fromPermission(Permission $permission): self
    {
        return new self($permission->getName(), $permission->getTier(), $permission->isManaged());
    }

    /**
     * Creates the complete current persistence representation
     *
     * @phpstan-param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3 || !isset($data['name'], $data['tier'], $data['managed'])
            || !is_string($data['name']) || !is_string($data['tier']) || !is_bool($data['managed'])
        ) {
            throw new DomainException('Audit Permission state requires its complete current representation.');
        }

        return new self(
            PermissionName::fromString($data['name']),
            PermissionTier::from($data['tier']),
            $data['managed']
        );
    }

    /**
     * Returns admitted Permission definition facts
     *
     * @phpstan-return PermissionStatePayload
     */
    public function toArray(): array
    {
        return ['name' => $this->name->toString(), 'tier' => $this->tier->value, 'managed' => $this->managed];
    }
}
