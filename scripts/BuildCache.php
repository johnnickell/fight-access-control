<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling;

use stdClass;
use Throwable;

final class BuildCache
{
    public static function run(string $root, array $arguments): int
    {
        $configuration = BuildEvidence::read($root.'/etc/local-tooling.json');
        BuildEvidence::require(
            array_diff($arguments, ['--force', ...($configuration->build_options ?? [])]) === [],
            'Usage: ./bin/build [--force]'
        );
        $cache = $root.'/var/build.json';
        $force = in_array('--force', $arguments, true);
        if ($force) {
            self::invalidate($cache);
        }

        $evidence = new BuildEvidence($root, $configuration->outputs ?? []);
        $planning = $evidence->planning();
        self::notify('PLAN');
        $tracked = self::receive();
        BuildEvidence::require($planning === $evidence->planning(), 'Planning changed during validation');
        $source = self::fingerprint($evidence, $tracked);
        $reason = $force ? 'Explicit --force' : 'No qualifying successful receipt';
        if (!$force && $source !== null && is_file($cache)) {
            try {
                $original = self::reusable($root, $cache, $source, $evidence);
                self::notify('RECHECK');
                $tracked = self::receive();
                BuildEvidence::require($source === $evidence->source($tracked), 'Inputs changed during reuse checks');
                BuildEvidence::require($planning === $evidence->planning(), 'Planning changed during reuse checks');
                self::notify('HIT '.$original);

                return 0;
            } catch (Throwable $error) {
                $reason = $error->getMessage();
            }
        }

        self::invalidate($cache);
        self::notify('MISS '.$reason);
        if (!empty($configuration->build_prepare)) {
            $source = self::fingerprint($evidence, self::receive(), true);
        }

        return self::recordGate($root, $cache, $configuration, $evidence, $planning, $source, $reason);
    }

    private static function reusable(string $root, string $cache, string $source, BuildEvidence $evidence): string
    {
        $pointer = BuildEvidence::read($cache);
        $original = $root.'/'.$pointer->receipt;
        $resolved = realpath($original);
        BuildEvidence::require(
            $resolved !== false && str_starts_with($resolved, $root.'/.runs/build/'),
            'Invalid receipt path'
        );
        $receipt = BuildEvidence::read($original);
        BuildEvidence::require(
            $pointer->schema === 2 && $receipt->schema === 2
                && $receipt->exit === 0 && $receipt->verification_exit === 0,
            'Invalid original receipt'
        );
        BuildEvidence::require($pointer->receipt_sha256 === BuildEvidence::tree($original), 'Original receipt changed');
        BuildEvidence::require($receipt->source === $source, 'Source/dependencies/instructions changed');
        BuildEvidence::require(
            BuildEvidence::digest($receipt->outputs) === BuildEvidence::digest($evidence->artifacts()),
            'Generated artifacts changed or missing'
        );
        BuildEvidence::require(
            $receipt->log_sha256 === BuildEvidence::tree(dirname($original).'/build.log'),
            'Original evidence changed'
        );

        return $original;
    }

    private static function recordGate(
        string $root,
        string $cache,
        stdClass $configuration,
        BuildEvidence $evidence,
        string $planning,
        ?string $source,
        string $reason
    ): int {
        BuildEvidence::require(
            !is_link($root.'/.runs') && !is_link($root.'/.runs/build'),
            'Refusing aliased build evidence'
        );
        $run = '.runs/build/'.gmdate('Ymd\THis\Z').'-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($root.'/'.$run, 0700, true);
        $receiptPath = $root.'/'.$run.'/receipt.json';
        $receipt = [
            'schema'     => 2,
            'command'    => $configuration->gate,
            'started'    => microtime(true),
            'exit'       => null,
            'cache_miss' => $reason
        ];
        BuildEvidence::write($receiptPath, $receipt);
        self::notify('GATE '.$run.'/build.log');
        $result = self::receive();
        $status = $result->status;
        $receipt['exit'] = $status;
        $receipt['finished'] = microtime(true);
        $cacheable = $status === 0 && $source !== null;
        if ($cacheable) {
            try {
                BuildEvidence::require(
                    $source === $evidence->source($result->tracked),
                    'Build inputs changed during execution; no cache published'
                );
                BuildEvidence::require(
                    $planning === $evidence->planning(),
                    'Planning changed during execution; no cache published'
                );
                $receipt['source'] = $source;
                $receipt['outputs'] = $evidence->artifacts();
                $receipt['log_sha256'] = BuildEvidence::tree($root.'/'.$run.'/build.log');
            } catch (Throwable $error) {
                $receipt['verification_error'] = $error->getMessage();
                fwrite(STDERR, 'Build verification incomplete: '.$error->getMessage()."\n");
                $cacheable = false;
                $status = 1;
            }
        }

        $receipt['verification_exit'] = $status;
        $receipt['cacheable'] = $cacheable;
        BuildEvidence::write($receiptPath, $receipt);
        if ($cacheable) {
            BuildEvidence::write($cache, [
                'schema'         => 2,
                'receipt'        => $run.'/receipt.json',
                'receipt_sha256' => BuildEvidence::tree($receiptPath)
            ]);
        }

        self::notify('RECEIPT '.$receiptPath);

        return $status;
    }

    private static function notify(string $message): void
    {
        fwrite(STDOUT, $message."\n");
        fflush(STDOUT);
    }

    private static function receive(): mixed
    {
        $value = fgets(STDIN);
        BuildEvidence::require($value !== false, 'Host build ended before reporting its result');

        return json_decode($value, flags: JSON_THROW_ON_ERROR);
    }

    private static function invalidate(string $cache): void
    {
        clearstatcache(true, $cache);
        if (file_exists($cache) || is_link($cache)) {
            unlink($cache);
        }
    }

    private static function fingerprint(BuildEvidence $evidence, array $tracked, bool $prepared = false): ?string
    {
        try {
            return $evidence->source($tracked);
        } catch (Throwable $throwable) {
            $when = $prepared ? ' after preparation' : '';
            $next = $prepared ? '' : '; executing full gate';
            fwrite(STDERR, 'Build cache unavailable'.$when.': '.$throwable->getMessage().$next."\n");

            return null;
        }
    }
}
