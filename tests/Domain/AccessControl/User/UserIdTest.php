<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserId::class)]
final class UserIdTest extends TestCase
{
    public function test_it_creates_a_stable_non_empty_identifier(): void
    {
        $id = UserId::generate();

        self::assertNotSame('', $id->toString());
        self::assertSame($id->toString(), UserId::fromString($id->toString())->toString());
    }
}
