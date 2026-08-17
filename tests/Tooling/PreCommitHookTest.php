<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class PreCommitHookTest extends TestCase
{
    private string $directory;

    public function test_that_hook_runs_the_default_build_once_from_the_repository_root_with_stdin_disconnected(): void
    {
        $success = $this->runHook(0);

        self::assertSame(0, $success->getExitCode(), $success->getErrorOutput());
        self::assertSame(
            $this->directory."|0|eof\n",
            file_get_contents($this->directory.'/build.log')
        );

        unlink($this->directory.'/build.log');

        $failure = $this->runHook(37);

        self::assertSame(37, $failure->getExitCode());
        self::assertSame(
            $this->directory."|0|eof\n",
            file_get_contents($this->directory.'/build.log')
        );
    }

    public function test_that_repository_documents_opt_in_activation_and_bypass_without_a_pre_push_gate(): void
    {
        $repositoryRoot = dirname(__DIR__, 2);
        $documentation = file_get_contents($repositoryRoot.'/CONTRIBUTING.md');

        self::assertFileExists($repositoryRoot.'/.githooks/pre-commit');
        self::assertStringContainsString('git config core.hooksPath .githooks', $documentation);
        self::assertStringContainsString('git commit --no-verify', $documentation);
        self::assertFileDoesNotExist($repositoryRoot.'/.githooks/pre-push');
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fight-access-control-pre-commit-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/.githooks', 0777, true);
        mkdir($this->directory.'/bin', 0777, true);
        mkdir($this->directory.'/nested/invocation', 0777, true);

        $hook = dirname(__DIR__, 2).'/.githooks/pre-commit';
        if (is_file($hook)) {
            copy($hook, $this->directory.'/.githooks/pre-commit');
            chmod($this->directory.'/.githooks/pre-commit', 0755);
        }

        file_put_contents($this->directory.'/bin/build', <<<'BASH'
#!/usr/bin/env bash
set -u

stdin_state='input'
if ! IFS= read -r _; then
    stdin_state='eof'
fi

printf '%s|%s|%s\n' "$PWD" "$#" "${stdin_state}" >> "${FAKE_BUILD_LOG}"
exit "${FAKE_BUILD_STATUS}"
BASH
        );
        chmod($this->directory.'/bin/build', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function runHook(int $buildStatus): Process
    {
        $process = new Process(
            [$this->directory.'/.githooks/pre-commit'],
            $this->directory.'/nested/invocation',
            [
                'FAKE_BUILD_LOG'    => $this->directory.'/build.log',
                'FAKE_BUILD_STATUS' => (string) $buildStatus
            ],
            "caller input\n",
            20
        );
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
