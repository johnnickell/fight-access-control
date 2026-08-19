<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class QualityGateTest extends TestCase
{
    private string $directory;

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failureProvider(): iterable
    {
        yield 'Composer' => ['composer validate', 11];
        yield 'PHPStan' => ['php -d memory_limit=512M vendor/bin/phpstan', 22];
        yield 'unassigned architecture' => ['php vendor/bin/deptrac debug:unassigned', 32];
        yield 'current-tree package boundary' => ['php tests/Architecture/PackageBoundaryTest.php', 33];
        yield 'negative-fixture package boundary' => ['php tests/Architecture/PackageBoundaryBehaviorTest.php', 34];
        yield 'documentation' => ['documentation-check', 41];
        yield 'production autoload' => ['production-autoload-check', 42];
    }

    public function test_that_quality_executes_every_gate_once_in_order(): void
    {
        $process = $this->runQuality();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            [
                'composer validate --strict --no-interaction',
                'php -l rector.php',
                'php -l contracts/Example.php',
                'php -l scripts/Tool.php',
                'php -l src/Example.php',
                'php -l tests/ExampleTest.php',
                'planning-check',
                'php vendor/bin/phpcs',
                'php -d memory_limit=512M vendor/bin/phpstan analyse',
                'php vendor/bin/deptrac --fail-on-uncovered --report-uncovered --report-skipped',
                'php vendor/bin/deptrac debug:unassigned --no-cache',
                'php tests/Architecture/PackageBoundaryTest.php',
                'php tests/Architecture/PackageBoundaryBehaviorTest.php',
                implode(' ', [
                    'php vendor/bin/rector process src/ contracts/',
                    'tests/Tooling/ tests/Domain/ tests/Application/ scripts/ --dry-run',
                ]),
                'php vendor/bin/phpunit --fail-on-skipped',
                'coverage',
                'documentation-check',
                'production-autoload-check'
            ],
            file($this->directory.'/commands.log', FILE_IGNORE_NEW_LINES)
        );
        $currentTreePosition = strpos($process->getOutput(), '[quality] Package boundary current tree');
        $negativeFixturePosition = strpos($process->getOutput(), '[quality] Package boundary negative fixtures');
        $rectorPosition = strpos($process->getOutput(), '[quality] Rector dry-run');
        self::assertNotFalse($currentTreePosition);
        self::assertNotFalse($negativeFixturePosition);
        self::assertNotFalse($rectorPosition);
        self::assertLessThan($negativeFixturePosition, $currentTreePosition);
        self::assertLessThan($rectorPosition, $negativeFixturePosition);
        self::assertSame(1, substr_count($process->getOutput(), '[quality] Production autoloading'));
    }

    #[DataProvider('failureProvider')]
    public function test_that_quality_fails_fast_and_preserves_status(string $prefix, int $status): void
    {
        $process = $this->runQuality($prefix, $status);

        self::assertSame($status, $process->getExitCode());
        $commands = file($this->directory.'/commands.log', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($commands);
        self::assertStringStartsWith($prefix, (string) end($commands));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fight-access-control-quality-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bin', 0777, true);
        mkdir($this->directory.'/src', 0777, true);
        mkdir($this->directory.'/contracts', 0777, true);
        mkdir($this->directory.'/tests', 0777, true);
        mkdir($this->directory.'/scripts', 0777, true);
        foreach (
            [
                'rector.php',
                'src/Example.php',
                'contracts/Example.php',
                'tests/ExampleTest.php',
                'scripts/Tool.php',
            ] as $file
        ) {
            file_put_contents($this->directory.'/'.$file, "<?php\n");
        }

        $commands = [
            'composer',
            'php',
            'planning-check',
            'coverage',
            'documentation-check',
            'production-autoload-check'
        ];
        foreach ($commands as $command) {
            $this->writeCommand($command);
        }

        copy(dirname(__DIR__, 2).'/bin/quality', $this->directory.'/bin/quality');
        chmod($this->directory.'/bin/quality', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function runQuality(string $failPrefix = '', int $failStatus = 0): Process
    {
        $process = new Process(['bash', 'bin/quality'], $this->directory, [
            'PATH'                => $this->directory.'/bin:'.(getenv('PATH') ?: ''),
            'QUALITY_COMMAND_LOG' => $this->directory.'/commands.log',
            'QUALITY_FAIL_PREFIX' => $failPrefix,
            'QUALITY_FAIL_STATUS' => (string) $failStatus
        ]);
        $process->run();

        return $process;
    }

    private function writeCommand(string $name): void
    {
        file_put_contents($this->directory.'/bin/'.$name, <<<'BASH'
#!/usr/bin/env bash
set -eu
line="$(basename "$0")"
if (( $# > 0 )); then line="${line} $*"; fi
printf '%s\n' "${line}" >> "${QUALITY_COMMAND_LOG}"
if [[ -n "${QUALITY_FAIL_PREFIX:-}" && "${line}" == "${QUALITY_FAIL_PREFIX}"* ]]; then
    exit "${QUALITY_FAIL_STATUS}"
fi
BASH);
        chmod($this->directory.'/bin/'.$name, 0755);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
