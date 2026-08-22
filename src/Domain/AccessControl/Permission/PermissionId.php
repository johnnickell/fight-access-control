<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Represents a stable permission identifier.
 */
final readonly class PermissionId extends UniqueId
{
}
