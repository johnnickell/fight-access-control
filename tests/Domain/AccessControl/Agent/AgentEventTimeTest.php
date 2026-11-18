<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentEventTime;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentEventTime::class)]
final class AgentEventTimeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function instants(): iterable
    {
        yield 'UTC' => ['2030-01-01T12:34:56Z', '2030-01-01T12:34:56.000000+00:00'];
        yield 'positive offset' => ['2030-01-01T12:34:56+02:30', '2030-01-01T12:34:56.000000+02:30'];
        yield 'negative offset' => ['2030-01-01T12:34:56.1-02:30', '2030-01-01T12:34:56.100000-02:30'];
        yield 'fractional' => ['2030-01-01T12:34:56.123456Z', '2030-01-01T12:34:56.123456+00:00'];
    }

    #[DataProvider('instants')]
    public function test_explicit_instants_preserve_precision_and_offset(string $input, string $canonical): void
    {
        self::assertSame($canonical, AgentEventTime::fromString($input)->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInstants(): iterable
    {
        foreach (
            ['', 'now', '2030-01-01', '2030-02-30T12:00:00Z', '2030-99-01T12:00:00Z',
            '2030-01-01T24:00:00Z', '2030-01-01T12:00:00+24:00', '2030-01-01T12:00:00+02:60',
            '2030-01-01T12:00:00.1234567Z', '2030-01-01T12:00:00Z\n'] as $value
        ) {
            yield 'invalid '.$value => [$value];
        }
    }

    #[DataProvider('invalidInstants')]
    public function test_invalid_calendar_and_implicit_or_normalized_instants_reject(string $value): void
    {
        $this->expectException(DomainException::class);
        AgentEventTime::fromString($value);
    }
}
