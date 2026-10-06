<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Attribute;

use Attribute;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

/**
 * Class RequiresAgentPermission
 *
 * Declares one conjunctive direct-Permission requirement on a protected Tool handle method.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RequiresAgentPermission
{
    private PermissionName $permissionName;

    /**
     * Constructs RequiresAgentPermission
     */
    public function __construct(string $permissionName)
    {
        $this->permissionName = PermissionName::fromString($permissionName);
    }

    /**
     * Returns the canonical required Permission name
     */
    public function getPermissionName(): PermissionName
    {
        return $this->permissionName;
    }
}
