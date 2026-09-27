<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;

/**
 * Simulates an adapter-supplied subtype misreporting its persisted identity.
 */
final class ImpersonatingRole extends Role
{
    /**
     * Reports a name other than its reconstructed name
     */
    public function getName(): RoleName
    {
        return RoleName::fromString(self::SUPER_ADMIN_NAME);
    }
}
