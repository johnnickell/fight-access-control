<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\Identity;

use Fight\AccessControl\Domain\Identity\EmailAddress;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailAddress::class)]
final class EmailAddressTest extends TestCase
{
    public function test_it_canonicalizes_a_valid_email_address(): void
    {
        $email = EmailAddress::fromString(' Alice@Example.Test ');

        self::assertSame('alice@example.test', $email->value());
    }

    public function test_it_rejects_an_invalid_email_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailAddress::fromString('not-an-email');
    }

    public function test_it_compares_canonical_email_addresses(): void
    {
        self::assertTrue(
            EmailAddress::fromString('Alice@example.test')->equals(EmailAddress::fromString('alice@example.test'))
        );
    }
}
