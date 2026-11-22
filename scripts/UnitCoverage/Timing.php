<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\UnitCoverage;

use RuntimeException;

final class Timing
{
    public static function read(string $path, array $identities): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read timing log');
        }

        // Match Python's splitlines() and validate UTF-8 even on lines without test events.
        $lines = preg_split('/\r\n|[\r\n\v\f\x1C-\x1E\x{85}\x{2028}\x{2029}]/u', $contents);
        if ($lines === false) {
            throw new RuntimeException('Cannot split timing log: '.preg_last_error_msg());
        }

        $counts = array_fill_keys(Enumeration::CATEGORIES, ['runnable' => 0, 'skipped' => 0, 'milliseconds' => '0']);
        $current = null;
        $seen = [];
        $ignored = false;
        $failures = 0;
        foreach ($lines as $line) {
            if (preg_match('/\A##teamcity\[test(?:Started|Failed|Ignored|Finished)/', $line) !== 1) {
                continue;
            }

            $pattern = '/\A##teamcity\[(testStarted|testFailed|testIgnored|testFinished)( .*)\]\z/u';
            if (preg_match($pattern, $line, $parts) !== 1) {
                throw new RuntimeException('Malformed TeamCity test event');
            }

            [, $event, $text] = $parts;
            $fields = self::attributes($text);
            if ($event === 'testStarted') {
                if ($current !== null) {
                    throw new RuntimeException('Nested test start without matching finish');
                }

                $current = self::start($fields, $identities, $seen);
                $ignored = false;
                continue;
            }

            if ($current === null || [$fields['name'], $fields['flowId']] !== [$current['name'], $current['flowId']]) {
                throw new RuntimeException('Unpaired or mismatched timing event: '.$event);
            }

            if ($event === 'testFailed') {
                ++$failures;
                continue;
            }

            if ($event === 'testIgnored') {
                if ($ignored) {
                    throw new RuntimeException('Duplicate ignored event');
                }

                $ignored = true;
                continue;
            }

            $duration = Milliseconds::parse($fields['duration']);
            $identity = $current['identity'];
            $category = $identities[$identity];
            ++$counts[$category][$ignored ? 'skipped' : 'runnable'];
            $counts[$category]['milliseconds'] = Milliseconds::add($counts[$category]['milliseconds'], $duration);

            $seen[$identity] = true;
            $current = null;
        }

        if ($current !== null) {
            throw new RuntimeException('Incomplete timing log');
        }

        if (count($seen) !== count($identities)) {
            throw new RuntimeException(sprintf(
                'Expanded timing/enumeration membership mismatch: %d/%d',
                count($seen),
                count($identities)
            ));
        }

        return [$counts, $failures];
    }

    private static function start(array $fields, array $identities, array $seen): array
    {
        $parts = explode('::', $fields['locationHint'], 3);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed timing locationHint');
        }

        [$file, $class, $name] = $parts;
        // Keep the complete data-set label. Inherited tests can name their declaring file.
        $method = preg_replace('/\A(\w+) with data set #([0-9]+)\z/u', '$1#$2', $name);
        $method = preg_replace('/\A(\w+) with data set "(.*)"\z/u', '$1#$2', $method);

        $identity = ltrim($class, '\\').'::'.$method;
        if (!isset($identities[$identity])) {
            throw new RuntimeException('Unknown expanded timing test: '.$identity);
        }

        if (!str_starts_with($file, 'php_qn:///') || $fields['name'] !== $name) {
            throw new RuntimeException('Timing name/location identity mismatch: '.$identity);
        }

        if (isset($seen[$identity])) {
            throw new RuntimeException('Duplicate timing execution: '.$identity);
        }

        return ['identity' => $identity, 'name' => $name, 'flowId' => $fields['flowId']];
    }

    private static function attributes(string $text): array
    {
        $fields = [];
        $escapes = ['|n' => "\n", '|r' => "\r", "|'" => "'", '|[' => '[', '|]' => ']', '||' => '|'];
        while ($text !== '') {
            if (
                preg_match("/\\A (\\w+)='((?:\\|[nr'\\[\\]|]|[^'|\\r\\n])*)'/u", $text, $parts) !== 1
                || isset($fields[$parts[1]])
            ) {
                throw new RuntimeException('Malformed or duplicate TeamCity attribute');
            }

            $fields[$parts[1]] = strtr($parts[2], $escapes);
            $text = substr($text, strlen($parts[0]));
        }

        return $fields;
    }
}
