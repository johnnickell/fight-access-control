<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\RefreshSession;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefreshCredential::class)]
final class RefreshCredentialContractTest extends TestCase
{
    /** @phpstan-return iterable<string, array{string}> */
    public static function invalidCredentials(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 63)];
        yield 'long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'non-hex' => [str_repeat('g', 64)];
        yield 'trailing newline' => [str_repeat('a', 64)."\n"];
        yield 'leading whitespace' => [' '.str_repeat('a', 64)];
    }

    #[DataProvider('invalidCredentials')]
    public function test_only_the_exact_supported_transport_shape_is_accepted(string $credential): void
    {
        $this->expectException(DomainException::class);
        RefreshCredential::fromString($credential);
    }

    public function test_valid_credential_round_trips_and_digests_without_transformation(): void
    {
        $raw = str_repeat('a', 64);
        $credential = RefreshCredential::fromString($raw);
        self::assertSame($raw, $credential->toString());
        self::assertSame(hash('sha256', $raw), $credential->digest());
    }
}
