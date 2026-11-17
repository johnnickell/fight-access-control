<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Router\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class NativePermissionTest extends TestCase
{
    #[DataProviderExternal(PermissionNativeScenario::class, 'forms')]
    public function test_native_permission_route_filter_controller_and_response_lifecycle(
        string $form,
        bool $grant,
        int $status
    ): void {
        // Native production pre_system intentionally replaces all output buffers. A real PHP
        // process, rather than PHPUnit's buffered child runner, preserves that native hook.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__.'/PermissionNativeProcess.php',
                $form,
                $grant ? '1' : '0',
                (string) $status,
                dirname(new ReflectionClass(Router::class)->getFileName(), 2).'/Common.php'
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors.$output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('production', $result['environment']);
        self::assertSame('', $errors);
        $this->addToAssertionCount($result['assertions']);
    }
}
