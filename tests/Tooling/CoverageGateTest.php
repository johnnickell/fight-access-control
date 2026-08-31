<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class CoverageGateTest extends TestCase
{
    private string $directory;

    public function test_that_coverage_gate_rejects_every_ignore_directive_before_validating_clover(): void
    {
        foreach (['@codeCoverageIgnore', '@codeCoverageIgnoreStart', '@codeCoverageIgnoreEnd'] as $directive) {
            file_put_contents($this->directory.'/src/Example.php', "<?php\n\n// {$directive}\n");

            $process = $this->runCoverageGate();

            self::assertSame(1, $process->getExitCode());
            self::assertSame(
                "Coverage-ignore directive found in production PHP: src/Example.php\n",
                $process->getErrorOutput()
            );
        }
    }

    public function test_that_coverage_gate_requires_an_exact_valid_clover_report(): void
    {
        file_put_contents($this->directory.'/src/Example.php', "<?php\n");
        mkdir($this->directory.'/var/reports/coverage', 0777, true);
        $incompleteReport = implode('', [
            '<?xml version="1.0"?><coverage><project>',
            '<metrics statements="2" coveredstatements="1" />',
            '</project></coverage>',
        ]);
        $exactReport = implode('', [
            '<?xml version="1.0"?><coverage><project>',
            '<metrics statements="2" coveredstatements="2" />',
            '</project></coverage>',
        ]);

        foreach (
            [
                'malformed XML' => ['<coverage><project>', 1, 'Clover report is malformed'],
                'missing metrics' => [
                    '<?xml version="1.0"?><coverage><project /></coverage>',
                    1,
                    'Clover project statement metrics are missing',
                ],
                'incomplete coverage' => [
                    $incompleteReport,
                    1,
                    'Statement coverage is incomplete: 1/2 statements covered',
                ],
                'exact coverage' => [
                    $exactReport,
                    0,
                    'Statement coverage is exact: 2/2 statements covered',
                ],
            ] as [$report, $expectedStatus, $expectedMessage]
        ) {
            file_put_contents($this->directory.'/var/reports/coverage/clover.xml', $report);

            $process = $this->runCoverageGate();

            self::assertSame($expectedStatus, $process->getExitCode());
            self::assertStringContainsString(
                $expectedMessage,
                $expectedStatus === 0 ? $process->getOutput() : $process->getErrorOutput()
            );
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fight-access-control-coverage-gate-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function runCoverageGate(): Process
    {
        $process = new Process(['bash', dirname(__DIR__, 2).'/bin/coverage'], $this->directory);
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
