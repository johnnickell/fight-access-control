<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\TypeContracts;

use stdClass;
use Symfony\Component\Process\Process;

final class Analyzer
{
    public static function run(string $root, string $file, float $timeout): array
    {
        return self::capture([
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

    public static function capture(array $command, string $root, string $file, float $timeout): array
    {
        // PHPStan can restart itself without Xdebug. Terminate its whole session on timeout.
        $process = new Process(['setsid', ...$command], $root, timeout: null);
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $process->start();
        $pid = $process->getPid();
        while ($process->isRunning()) {
            if (hrtime(true) >= $deadline) {
                if ($pid !== null) {
                    posix_kill(-$pid, 9);
                }

                // Also stops the leader if the deadline fired before setsid created the group.
                $process->stop(0, 9);
                throw new ContractFailure(
                    "Analyzer timed out for $file\n".$process->getOutput()."\n".$process->getErrorOutput()
                );
            }

            usleep(10_000);
        }

        return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
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
