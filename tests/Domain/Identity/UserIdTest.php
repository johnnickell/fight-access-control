<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\Identity;

use Fight\AccessControl\Domain\Identity\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserId::class)]
final class UserIdTest extends TestCase
{
    public function test_it_creates_a_stable_non_empty_identifier(): void
    {
        $id = UserId::generate();

        self::assertNotSame('', $id->value());
        self::assertSame($id->value(), UserId::fromString($id->value())->value());
    }

    public function test_it_rejects_an_empty_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserId::fromString('');
    }
}
