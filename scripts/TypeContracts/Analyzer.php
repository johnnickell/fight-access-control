<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\TypeContracts;

use stdClass;

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
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $process = proc_open(['setsid', ...$command], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes, $root);
        if (!is_resource($process)) {
            throw new ContractFailure("Cannot launch analyzer for $file");
        }

        $status = proc_get_status($process);
        $pid = $status['pid'];
        $output = [1 => '', 2 => ''];
        try {
            foreach ($pipes as $pipe) {
                FixtureSuite::require(stream_set_blocking($pipe, false), 'Cannot configure analyzer pipe');
            }

            while (true) {
                $this->requireNotInterrupted();
                $remaining = $deadline - hrtime(true);
                FixtureSuite::require($remaining > 0, 'Analyzer timed out');
                if (!$status['running'] && $pipes === []) {
                    $code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];

                    return [$code, $output[1], $output[2]];
                }

                // Bound each wait so SIGINT and exit are observed even with idle pipes.
                $wait = (int) min(10_000, ceil($remaining / 1_000));
                $this->readOutput($pipes, $output, $wait);
                if ($status['running']) {
                    $status = proc_get_status($process);
                }
            }
        } catch (ContractFailure $contractFailure) {
            if ($status['running']) {
                // Stop the leader even if it has not entered the new group yet.
                posix_kill($pid, SIGKILL);
            }

            // Descendants can still hold the pipes after the leader has exited.
            posix_kill(-$pid, SIGKILL);
            foreach ($pipes as $fd => $pipe) {
                // Collect pending output without waiting for EOF or an escaped writer.
                if (stream_set_blocking($pipe, false)) {
                    $output[$fd] .= stream_get_contents($pipe, 65_536);
                }
            }

            throw new ContractFailure(
                $contractFailure->getMessage()." for $file\n".$output[1]."\n".$output[2],
                previous: $contractFailure
            );
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
        }
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

    private function readOutput(array &$pipes, array &$output, int $wait): void
    {
        if ($pipes === []) {
            usleep($wait);

            return;
        }

        $readable = $pipes;
        $write = null;
        $except = null;
        if (@stream_select($readable, $write, $except, 0, $wait) === false) {
            // An interrupted select is expected on SIGINT; preserve the controlled failure.
            $this->requireNotInterrupted();
            throw new ContractFailure('Cannot wait for analyzer output');
        }

        foreach ($readable as $fd => $pipe) {
            $chunk = fread($pipe, 8192);
            FixtureSuite::require($chunk !== false, 'Cannot read analyzer output');
            $output[$fd] .= $chunk;
            if (feof($pipe)) {
                fclose($pipe);
                unset($pipes[$fd]);
            }
        }
    }
}
