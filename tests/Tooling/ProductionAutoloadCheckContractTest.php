<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class ProductionAutoloadCheckContractTest extends TestCase
{
    private string $directory;

    public function test_that_it_checks_an_isolated_authoritative_production_install(): void
    {
        $process = $this->runCheck();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $composerLog = trim((string) file_get_contents($this->directory.'/composer.log'));
        [$installRoot, $composerArguments] = explode('|', $composerLog, 2);

        self::assertNotSame($this->directory, $installRoot);
        self::assertStringStartsWith($this->directory.'/tmp/fight-access-control-production.', $installRoot);
        self::assertSame(
            'install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative',
            $composerArguments
        );

        $phpLog = trim((string) file_get_contents($this->directory.'/php.log'));
        [$checkRoot, $phpArguments] = explode('|', $phpLog, 2);
        self::assertSame($installRoot, $checkRoot);
        self::assertSame(
            $this->directory.'/tests/Architecture/ProductionInstallTest.php '.$installRoot,
            $phpArguments
        );
        self::assertDirectoryDoesNotExist($installRoot);
        self::assertFileExists($this->directory.'/vendor/root-install-marker');
    }

    public function test_that_it_propagates_a_failed_install_and_cleans_the_isolated_tree(): void
    {
        $process = $this->runCheck(composerStatus: 42);

        self::assertSame(42, $process->getExitCode());
        $composerLog = trim((string) file_get_contents($this->directory.'/composer.log'));
        [$installRoot] = explode('|', $composerLog, 2);
        self::assertDirectoryDoesNotExist($installRoot);
        self::assertFileDoesNotExist($this->directory.'/php.log');
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fight-access-control-production-check-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bin', 0777, true);
        mkdir($this->directory.'/src/Domain', 0777, true);
        mkdir($this->directory.'/src/Application', 0777, true);
        mkdir($this->directory.'/tests/Architecture', 0777, true);
        mkdir($this->directory.'/vendor', 0777, true);
        mkdir($this->directory.'/tmp', 0777, true);

        copy(dirname(__DIR__, 2).'/bin/production-autoload-check', $this->directory.'/bin/production-autoload-check');
        chmod($this->directory.'/bin/production-autoload-check', 0755);
        file_put_contents($this->directory.'/composer.json', "{}\n");
        file_put_contents($this->directory.'/composer.lock', "{\"packages\": []}\n");
        file_put_contents($this->directory.'/src/Domain/Contract.php', "<?php\n");
        file_put_contents($this->directory.'/src/Application/UseCase.php', "<?php\n");
        file_put_contents($this->directory.'/tests/Architecture/ProductionInstallTest.php', "<?php exit(0);\n");
        file_put_contents($this->directory.'/vendor/root-install-marker', "must remain untouched\n");

        file_put_contents($this->directory.'/composer', <<<'BASH'
#!/usr/bin/env bash
set -eu
test -f composer.json
test -f composer.lock
test -f src/Domain/Contract.php
test -f src/Application/UseCase.php
printf '%s|%s\n' "$PWD" "$*" >> "${FAKE_COMPOSER_LOG}"
mkdir -p vendor
printf '%s\n' '<?php' > vendor/autoload.php
exit "${FAKE_COMPOSER_STATUS:-0}"
BASH);
        chmod($this->directory.'/composer', 0755);

        file_put_contents($this->directory.'/php', <<<'BASH'
#!/usr/bin/env bash
set -eu
printf '%s|%s\n' "$PWD" "$*" >> "${FAKE_PHP_LOG}"
exit "${FAKE_PHP_STATUS:-0}"
BASH);
        chmod($this->directory.'/php', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function runCheck(int $composerStatus = 0): Process
    {
        $process = new Process(['bash', 'bin/production-autoload-check'], $this->directory, [
            'COMPOSER_BIN'         => $this->directory.'/composer',
            'FAKE_COMPOSER_LOG'    => $this->directory.'/composer.log',
            'FAKE_COMPOSER_STATUS' => (string) $composerStatus,
            'FAKE_PHP_LOG'         => $this->directory.'/php.log',
            'PHP_BIN'              => $this->directory.'/php',
            'TMPDIR'               => $this->directory.'/tmp'
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
