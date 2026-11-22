<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\TypeContracts;

use stdClass;
use Symfony\Component\Process\Process;

final class Analyzer
{
    private bool $interrupted = false;

    public function __construct()
    {
        $interrupt = function (): void {
            $this->interrupted = true;
        };
        if (!function_exists('pcntl_signal') || !pcntl_signal(SIGINT, $interrupt)) {
            throw new ContractFailure('Analyzer requires pcntl SIGINT handling in the tooling runtime');
        }
    }

    public static function check(stdClass $case, array $result): void
    {
        [$code, $stdout, $stderr] = $result;
        $id = $case->id;
        FixtureSuite::require($code === 1, "$id: expected analyzer diagnostic exit 1, got $code");
        FixtureSuite::require(
            !FixtureSuite::hasText($stderr) && preg_match('//u', $stderr) === 1,
            "$id: unexpected stderr"
        );
        $report = json_decode($stdout, flags: JSON_THROW_ON_ERROR);
        FixtureSuite::require(
            FixtureSuite::hasFields($report, ['totals', 'files', 'errors']),
            "$id: incomplete report"
        );
        $count = count($case->diagnostics);
        FixtureSuite::require(
            $report->errors === [] && FixtureSuite::hasFields($report->totals, ['errors', 'file_errors'])
            && $report->totals->errors === 0 && $report->totals->file_errors === $count,
            "$id: unexpected global errors or diagnostic totals"
        );
        FixtureSuite::require(
            FixtureSuite::hasFields($report->files, [$case->file]),
            "$id: unexpected/missing diagnostic file"
        );
        $output = $report->files->{$case->file};
        FixtureSuite::require(
            $output instanceof stdClass && ($output->errors ?? null) === $count && is_array($output->messages ?? null),
            "$id: incomplete diagnostic messages"
        );
        $expected = self::diagnosticCounts($case->diagnostics);
        $actual = self::diagnosticCounts($output->messages);
        FixtureSuite::require($expected == $actual, "$id: diagnostic mismatch against case manifest");
    }

    public function run(string $root, string $file, float $timeout): array
    {
        return $this->capture([
            PHP_BINARY,
            '-d',
            'memory_limit=512M',
            $root.'/vendor/bin/phpstan',
            'analyse',
            '-c',
            $root.'/phpstan-type-contracts.neon',
            '--no-progress',
            '--no-ansi',
            '--error-format=json',
            $file
        ], $root, $file, $timeout);
    }

    public function requireNotInterrupted(): void
    {
        pcntl_signal_dispatch();
        if ($this->interrupted) {
            throw new ContractFailure('Analyzer interrupted');
        }
    }

    public function capture(array $command, string $root, string $file, float $timeout): array
    {
        $this->requireNotInterrupted();
        // PHPStan can restart itself without Xdebug, so give it a separate process group.
        $process = new Process(['setsid', ...$command], $root, timeout: null);
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $process->start();
        $pid = $process->getPid();
        try {
            while (true) {
                $this->requireNotInterrupted();
                if (!$process->isRunning()) {
                    break;
                }

                if (hrtime(true) >= $deadline) {
                    throw new ContractFailure('Analyzer timed out');
                }

                usleep(10_000);
            }
        } catch (ContractFailure $contractFailure) {
            if ($pid !== null) {
                // Stop the leader even if it has not entered the new group yet, then kill its descendants.
                posix_kill($pid, SIGKILL);
                posix_kill(-$pid, SIGKILL);
            }

            $process->stop(0, SIGKILL);
            throw new ContractFailure(
                $contractFailure->getMessage()." for $file\n".$process->getOutput()."\n".$process->getErrorOutput(),
                previous: $contractFailure
            );
        }

        $this->requireNotInterrupted();

        return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    private static function diagnosticCounts(array $messages): array
    {
        $counts = [];
        foreach ($messages as $message) {
            FixtureSuite::require(
                $message instanceof stdClass && is_int($message->line ?? null)
                && is_string($message->identifier ?? null) && is_string($message->message ?? null),
                'Malformed diagnostic line, identifier or message'
            );
            $key = json_encode([$message->line, $message->identifier, $message->message], JSON_THROW_ON_ERROR);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
