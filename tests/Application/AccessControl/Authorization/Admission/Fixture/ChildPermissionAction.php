<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\Fixture;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;

#[RequiresPermission('CHILD_CLASS')]
#[RequiresPermission('DUPLICATE')]
final class ChildPermissionAction extends ParentPermissionAction
{
    #[RequiresPermission('CHILD_METHOD')]
    #[RequiresPermission('DUPLICATE')]
    public function execute(): string
    {
        return 'direct child execution';
    }

    #[RequiresPermission('CHILD_INVOKABLE')]
    public function __invoke(): string
    {
        return 'direct invocation';
    }
}
