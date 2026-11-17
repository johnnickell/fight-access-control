<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use RuntimeException;

final class PermissionController extends PermissionParent
{
    public static array $trace = [];

    public function __construct()
    {
        self::$trace[] = 'construct';
    }

    #[RequiresPermission('AGENT_PROFILE_READ')]
    #[RequiresPermission('CHILD_READ')]
    public function handle(string $id = ''): string
    {
        self::$trace[] = 'action';

        return 'admitted:'.$id;
    }

    #[RequiresPermission('private-permission-sentinel')]
    public function malformed(): string
    {
        return 'must not execute';
    }

    #[PermissionNativeFault]
    public function nativeFault(): string
    {
        return $this->inherited();
    }

    public function failure(): never
    {
        echo 'private partial output';
        throw new RuntimeException('private action fault', 403);
    }

    public function __invoke(string $id = ''): string
    {
        return $this->inherited().':'.$id;
    }
}
