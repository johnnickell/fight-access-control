<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;

#[RequiresPermission('AGENT_PROFILE_READ')]
final class PermissionNativeRead extends NativeController
{
}
