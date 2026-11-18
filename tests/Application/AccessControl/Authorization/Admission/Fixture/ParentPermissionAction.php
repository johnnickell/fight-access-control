<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\Fixture;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use LogicException;

#[RequiresPermission('PARENT_CLASS')]
#[RequiresPermission('DUPLICATE')]
abstract class ParentPermissionAction
{
    public function __construct()
    {
        throw new LogicException('Metadata must not construct controllers.');
    }

    #[RequiresPermission('PARENT_METHOD')]
    #[RequiresPermission('DUPLICATE')]
    public function execute(): string
    {
        return 'parent';
    }

    #[RequiresPermission('INHERITED')]
    public function inherited(): string
    {
        return 'inherited';
    }

    #[RequiresPermission('PARENT_INVOKABLE')]
    public function __invoke(): string
    {
        return 'parent invocation';
    }
}
