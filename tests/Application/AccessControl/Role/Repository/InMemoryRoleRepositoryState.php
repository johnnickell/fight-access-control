<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\Repository;

use Fight\AccessControl\Domain\AccessControl\Role\Role;

final class InMemoryRoleRepositoryState
{
    /** @var list<Role> */
    public array $roles = [];
}
