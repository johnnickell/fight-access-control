<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Fight\AccessControl\Adapter\Http\CodeIgniter\NativeDiagnostic;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeNativeLogger;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Throwable;

#[CoversClass(NativeDiagnostic::class)]
#[CoversClass(SafeNativeLogger::class)]
final class SafeNativeLoggerTest extends TestCase
{
    public function test_native_strings_are_bounded_and_objects_are_never_interpolated(): void
    {
        $object = new class implements Stringable {
            public function __toString(): string
            {
                TestCase::fail('Private objects must never be converted.');
            }
        };
        $reports = [];
        $diagnostics = new class ($reports) implements SafeHttpDiagnostics {
            public function __construct(public array &$reports)
            {
            }

            public function report(Throwable $failure): void
            {
                $this->reports[] = $failure;
            }
        };
        $logger = new SafeNativeLogger($diagnostics);
        $logger->warning(str_repeat('p', 5000), [
            'private'            => str_repeat('s', 5000),
            'object'             => $object,
            'nested'             => [$object],
            5                    => 'untrusted numeric key',
            'exception'          => $object,
            str_repeat('k', 150) => 'bounded key'
        ] + array_fill_keys(range('a', 'z'), 'extra'));
        self::assertInstanceOf(NativeDiagnostic::class, $reports[0]);
        self::assertSame(str_repeat('p', 4096), $reports[0]->nativeMessage);
        self::assertSame(str_repeat('s', 4096), $reports[0]->nativeContext['private']);
        self::assertArrayNotHasKey('object', $reports[0]->nativeContext);
        self::assertArrayNotHasKey('nested', $reports[0]->nativeContext);
        self::assertArrayNotHasKey('z', $reports[0]->nativeContext);
        self::assertSame('bounded key', $reports[0]->nativeContext[str_repeat('k', 128)]);
        self::assertNull($reports[0]->getPrevious());
        self::assertSame('CodeIgniter native diagnostic.', $reports[0]->getMessage());
        $logger->debug($object, ['object' => $object]);
        self::assertNull($reports[1]->nativeMessage);
        self::assertSame([], $reports[1]->nativeContext);
        self::assertFalse($logger->failed());
    }

    public function test_an_actual_native_exception_reaches_the_same_owner_unchanged(): void
    {
        $failure = new RuntimeException('private-original');
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::identicalTo($failure));
        $logger = new SafeNativeLogger($diagnostics);
        $logger->error('ignored template', ['exception' => $failure]);
        self::assertFalse($logger->failed());
    }

    public function test_sink_failures_are_finite_and_reset_for_the_next_request(): void
    {
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->willThrowException(new RuntimeException('private sink'));
        $logger = new SafeNativeLogger($diagnostics);
        $logger->critical('private native diagnostic');
        self::assertTrue($logger->failed());
        $logger->reset();
        self::assertFalse($logger->failed());
    }

    public function test_native_logging_from_the_owner_cannot_recurse_or_claim_success(): void
    {
        $logger = null;
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->willReturnCallback(
            static function () use (&$logger): void {
                $logger->error('private recursive diagnostic');
            }
        );
        $logger = new SafeNativeLogger($diagnostics);
        $logger->error('outer record');
        self::assertTrue($logger->failed());
    }
}
