<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class BuildContractTest extends TestCase
{
    private string $directory;

    public function test_that_default_build_installs_the_lock_and_delegates_quality_once_noninteractively(): void
    {
        $process = $this->runBuild();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $log = (string) file_get_contents($this->directory.'/docker.log');
        self::assertSame(1, substr_count($log, 'build -t fight-access-control ./etc/docker/'));
        self::assertSame(1, substr_count($log, 'container run --rm'));
        self::assertSame(1, substr_count($log, './bin/quality'));
        self::assertStringContainsString(
            'composer install --no-interaction --no-progress --prefer-dist && ./bin/quality',
            $log
        );
        self::assertStringContainsString('-v '.$this->directory.':/app:delegated', $log);
        self::assertStringContainsString('--user 501:20', $log);
        preg_match('/^container run .*$/m', $log, $containerLine);
        self::assertNotEmpty($containerLine[0] ?? '');
        self::assertStringNotContainsString('-it', $containerLine[0]);
        self::assertStringNotContainsString(' -i ', $containerLine[0]);
        self::assertStringNotContainsString(' -t ', $containerLine[0]);
    }

    public function test_that_build_preserves_the_quality_failure_status(): void
    {
        self::assertSame(37, $this->runBuild(37)->getExitCode());
    }

    public function test_that_unsupported_arguments_fail_before_container_work(): void
    {
        $process = new Process(['bash', 'bin/build', '--unsupported'], $this->directory, [
            'DOCKER_BIN' => $this->directory.'/missing-docker'
        ]);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertSame("Usage: ./bin/build [--latest]\n", $process->getErrorOutput());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fight-access-control-build-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bin', 0777, true);
        copy(dirname(__DIR__, 2).'/bin/build', $this->directory.'/bin/build');
        chmod($this->directory.'/bin/build', 0755);
        file_put_contents($this->directory.'/composer.lock', "tracked resolution\n");
        file_put_contents($this->directory.'/docker', <<<'BASH'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "${FAKE_DOCKER_LOG}"
if [[ "${1:-}" == 'container' && "${2:-}" == 'run' ]]; then
    exit "${FAKE_GATE_STATUS:-0}"
fi
BASH);
        chmod($this->directory.'/docker', 0755);
        file_put_contents($this->directory.'/id', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == '-u' ]]; then echo 501; else echo 20; fi
BASH);
        chmod($this->directory.'/id', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function runBuild(int $gateStatus = 0): Process
    {
        $process = new Process(['bash', 'bin/build'], $this->directory, [
            'DOCKER_BIN'        => $this->directory.'/docker',
            'FAKE_DOCKER_LOG'   => $this->directory.'/docker.log',
            'FAKE_GATE_STATUS'  => (string) $gateStatus,
            'ID_BIN'            => $this->directory.'/id'
        ]);
        $process->run();

        return $process;
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
