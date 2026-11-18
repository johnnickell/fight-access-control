<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Controller;
use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;

#[RequiresPermission('AGENT_PROFILE_READ')]
class PermissionParent extends Controller
{
    public function inherited(): string
    {
        PermissionController::$trace[] = 'action';

        return 'admitted';
    }

    #[RequiresPermission('PARENT_READ')]
    public function handle(string $id = ''): string
    {
        return $id;
    }
}
