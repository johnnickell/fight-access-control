<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleId::class)]
final class RoleIdTest extends TestCase
{
    public function test_it_generates_and_restores_a_stable_identifier(): void
    {
        $id = RoleId::generate();
        $restored = RoleId::fromString($id->toString());

        self::assertNotSame('', $id->toString());
        self::assertTrue($id->equals($restored));
        self::assertSame($id->hashValue(), $restored->hashValue());
    }
}
