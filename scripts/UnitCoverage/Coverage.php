<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\UnitCoverage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

final class Coverage
{
    public static function read(string $root, string $path, array $identities): array
    {
        if (!str_starts_with($path, '/')) {
            $path = $root.'/'.$path;
        }

        // Native PHPUnit serialization is executable PHP. Load only trusted, locally generated artifacts.
        $artifact = new Unserializer()->unserialize($path);
        if ($artifact['basePath'] !== $root.'/src') {
            throw new RuntimeException('Coverage source root does not match this checkout');
        }

        $data = $artifact['codeCoverage'];

        $unit = [];
        $seen = [];
        $testIds = $data->testIds();
        foreach ($testIds as $index => $identity) {
            if (!is_int($index) || $index < 0 || !is_string($identity) || !isset($identities[$identity])) {
                throw new RuntimeException('Invalid or unknown expanded coverage test ID');
            }

            if (isset($seen[$identity])) {
                throw new RuntimeException('Duplicate coverage test: '.$identity);
            }

            $seen[$identity] = true;
            if ($identities[$identity] === 'Unit') {
                $unit[$index] = true;
            }
        }

        $knownIndexes = array_fill_keys(array_keys($testIds), true);
        $files = [];
        foreach ($data->lineCoverage() as $path => $lines) {
            $missing = [];
            $total = 0;
            if (!is_array($lines)) {
                throw new RuntimeException('Invalid line coverage for '.$path);
            }

            foreach ($lines as $line => $hits) {
                if (!is_int($line) || $line < 1) {
                    throw new RuntimeException('Invalid source line number in '.$path);
                }

                if ($hits === null) {
                    continue;
                }

                self::verifyHits($hits, $knownIndexes);
                ++$total;
                if (array_intersect_key($hits, $unit) === []) {
                    $missing[] = $line;
                }
            }

            $files['src/'.$path] = ['covered' => $total - count($missing), 'total' => $total, 'missing' => $missing];
        }

        self::verifyDenominator($root, $files);
        $branches = ['covered' => 0, 'total' => 0];
        foreach ($data->functionCoverage() as $functions) {
            foreach ($functions as $function) {
                foreach ($function->branches as $branch) {
                    self::verifyHits($branch->hit, $knownIndexes);
                    ++$branches['total'];
                    if (array_intersect_key($branch->hit, $unit) !== []) {
                        ++$branches['covered'];
                    }
                }
            }
        }

        $total = array_sum(array_column($files, 'total'));
        if ($total === 0) {
            throw new RuntimeException('No executable lines in coverage data');
        }

        return [
            'build_information' => $artifact['buildInformation'],
            'files'             => $files,
            'branches'          => $branches,
            'coverage_test_ids' => count($seen),
            'source_files'      => count($files),
            'source_exclusions' => [],
            'attribution'       => 'Unit test IDs only; single all-suite execution',
            'covered'           => array_sum(array_column($files, 'covered')),
            'total'             => $total
        ];
    }

    private static function verifyHits(array $hits, array $knownIndexes): void
    {
        foreach ($hits as $index => $count) {
            if (!isset($knownIndexes[$index]) || !is_int($count) || $count < 1) {
                throw new RuntimeException('Invalid per-test coverage hit');
            }
        }
    }

    private static function verifyDenominator(string $root, array $files): void
    {
        $source = [];
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
        foreach ($paths as $path) {
            if (str_ends_with($path->getFilename(), '.php')) {
                $source[substr($path->getPathname(), strlen($root) + 1)] = true;
            }
        }

        $difference = array_diff_key($source, $files) + array_diff_key($files, $source);
        if ($difference !== [] || $source === []) {
            throw new RuntimeException('Coverage source denominator mismatch: '.implode(', ', array_keys($difference)));
        }
    }
}
