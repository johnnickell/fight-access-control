<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionId::class)]
final class PermissionIdTest extends TestCase
{
    public function test_it_generates_and_restores_a_stable_identifier(): void
    {
        $id = PermissionId::generate();
        $restored = PermissionId::fromString($id->toString());

        self::assertNotSame('', $id->toString());
        self::assertTrue($id->equals($restored));
        self::assertSame($id->hashValue(), $restored->hashValue());
    }
}
