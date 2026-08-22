<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleName::class)]
final class RoleNameTest extends TestCase
{
    public function test_it_accepts_a_role_prefixed_uppercase_underscore_name(): void
    {
        $name = RoleName::fromString('ROLE_SUPER_ADMIN');

        self::assertSame('ROLE_SUPER_ADMIN', $name->toString());
        self::assertSame('ROLE_SUPER_ADMIN', (string) $name);
        self::assertTrue($name->equals(RoleName::fromString('ROLE_SUPER_ADMIN')));
    }

    public function test_it_rejects_names_outside_the_canonical_format(): void
    {
        foreach (
            [
                '',
                'ROLE_',
                'ADMIN',
                'role_admin',
                'ROLE-ADMIN',
                'ROLE ADMIN',
                'ROLE_ADMIN_1',
            ] as $invalidName
        ) {
            try {
                RoleName::fromString($invalidName);
                self::fail(sprintf('Expected "%s" to be rejected.', $invalidName));
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
