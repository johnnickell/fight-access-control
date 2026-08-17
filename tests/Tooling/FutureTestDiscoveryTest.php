<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
final class FutureTestDiscoveryTest extends TestCase
{
    private string $root;

    public function test_that_phpunit_discovers_future_package_tests_without_architecture_scripts(): void
    {
        $fixture = sys_get_temp_dir().'/fight-access-control-test-discovery-'.bin2hex(random_bytes(8));
        $representativeTests = [
            'tests/Domain/Identity/FutureIdentityTest.php',
            'tests/Application/Authentication/FutureAuthenticationTest.php',
            'tests/Architecture/ProceduralArchitectureTest.php'
        ];

        try {
            foreach ($representativeTests as $relativePath) {
                $path = $fixture.'/'.$relativePath;
                mkdir(dirname($path), 0777, true);
                file_put_contents($path, "<?php\n");
            }

            $configuredRoots = $this->phpunitTestRoots();
            $discoveredTests = [];
            foreach ($configuredRoots as $configuredRoot) {
                if (!is_dir($fixture.'/'.$configuredRoot)) {
                    mkdir($fixture.'/'.$configuredRoot, 0777, true);
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fixture.'/'.$configuredRoot)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                        $discoveredTests[] = substr($file->getPathname(), strlen($fixture) + 1);
                    }
                }
            }

            sort($discoveredTests);
            self::assertSame(
                [
                    'tests/Application/Authentication/FutureAuthenticationTest.php',
                    'tests/Domain/Identity/FutureIdentityTest.php'
                ],
                $discoveredTests
            );
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function test_that_static_quality_gates_include_future_package_test_roots(): void
    {
        $rector = $this->read('rector.php');
        $phpcs = $this->read('phpcs.xml');
        $phpstan = $this->read('phpstan.neon.dist');

        foreach (['tests/Tooling', 'tests/Domain', 'tests/Application'] as $testRoot) {
            self::assertStringContainsString("__DIR__.'/$testRoot'", $rector);
            self::assertStringContainsString("<file>$testRoot</file>", $phpcs);
        }

        self::assertStringContainsString('- tests', $phpstan);
        self::assertStringNotContainsString("__DIR__.'/tests/Architecture'", $rector);
        self::assertStringNotContainsString('<file>tests/Architecture</file>', $phpcs);
    }

    public function test_that_architecture_scripts_remain_explicit_quality_commands(): void
    {
        $quality = $this->read('bin/quality');

        self::assertStringContainsString('php tests/Architecture/PackageBoundaryTest.php', $quality);
        self::assertStringContainsString('php tests/Architecture/PackageBoundaryBehaviorTest.php', $quality);
        self::assertNotContains('tests/Architecture', $this->phpunitTestRoots());
    }

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private function phpunitTestRoots(): array
    {
        $configuration = simplexml_load_string($this->read('phpunit.xml.dist'));
        self::assertNotFalse($configuration);

        $directories = [];
        foreach ($configuration->testsuites->testsuite->directory as $directory) {
            $directories[] = (string) $directory;
        }

        return $directories;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root.'/'.$path);
        self::assertNotFalse($contents, 'Unable to read '.$path);

        return $contents;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

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
