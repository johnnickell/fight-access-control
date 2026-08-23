<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\Exception\PermissionNameException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionName::class)]
#[CoversClass(PermissionNameException::class)]
final class PermissionNameTest extends TestCase
{
    public function test_it_accepts_an_uppercase_underscore_name(): void
    {
        $name = PermissionName::fromString('MANAGE_USERS');

        self::assertSame('MANAGE_USERS', $name->toString());
        self::assertSame('MANAGE_USERS', (string) $name);
        self::assertTrue($name->equals(PermissionName::fromString('MANAGE_USERS')));
    }

    public function test_it_rejects_names_outside_the_canonical_format(): void
    {
        foreach (
            [
                '',
                '_',
                '_MANAGE_USERS',
                'MANAGE_USERS_',
                'MANAGE__USERS',
                'manage_users',
                'MANAGE-USERS',
                'MANAGE USERS',
                'MANAGE_USERS_1',
            ] as $invalidName
        ) {
            try {
                PermissionName::fromString($invalidName);
                self::fail(sprintf('Expected "%s" to be rejected.', $invalidName));
            } catch (PermissionNameException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
