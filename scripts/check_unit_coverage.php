<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\UnitCoverage\Coverage;
use Fight\AccessControl\Tooling\UnitCoverage\Enumeration;
use Fight\AccessControl\Tooling\UnitCoverage\Timing;

$root = dirname(__DIR__);
$usage = 'usage: check_unit_coverage.php [-h] [--coverage COVERAGE] [--tests TESTS]';
$usage .= ' [--timing TIMING] [--output OUTPUT] [--inspect]';
$options = [
    'coverage' => 'var/reports/coverage/coverage.php',
    'tests'    => 'var/reports/coverage/tests.xml',
    'timing'   => 'var/reports/coverage/teamcity.log',
    'output'   => 'var/reports/coverage/unit.json'
];
$names = [...array_keys($options), 'inspect', 'help'];
$isSeparatePath = static function (string $token) use ($names): bool {
    // Argparse treats lone hyphens and negative numbers as values, not options.
    if ($token === '-' || !str_starts_with($token, '-') || preg_match('/\A-(?:\d+|\d*\.\d+)\z/u', $token) === 1) {
        return true;
    }

    if (!str_contains($token, ' ') || str_starts_with($token, '-h')) {
        return false;
    }

    $flag = explode('=', $token, 2)[0];

    return !str_starts_with($flag, '--')
        || !array_any($names, static fn (string $name): bool => str_starts_with('--'.$name, $flag));
};
$inspect = false;
$extras = [];
try {
    for ($i = 1; $i < $argc; ++$i) {
        $argument = $argv[$i];
        if ($argument === '--') {
            array_push($extras, ...array_slice($argv, $i + 1));
            break;
        }

        [$flag, $value] = array_pad(explode('=', $argument, 2), 2, null);
        if (str_starts_with($argument, '-h')) {
            $suffix = ltrim(substr($argument, 2), 'h');
            if (str_starts_with($suffix, '=') || str_starts_with($suffix, '-')) {
                $explicit = str_starts_with($suffix, '=') ? substr($suffix, 1) : $suffix;
                $message = 'argument -h/--help: ignored explicit argument '.var_export($explicit, true);
                throw new InvalidArgumentException($message);
            }

            $flag = '--help';
            $value = null;
        }

        $name = str_starts_with($flag, '--') ? substr($flag, 2) : null;
        // Retain argparse's unique long-option abbreviations.
        $matches = $name === null ? [] : array_values(array_filter(
            $names,
            static fn (string $option): bool => str_starts_with($option, $name)
        ));
        if (count($matches) > 1) {
            throw new InvalidArgumentException('ambiguous option: '.$flag.' could match --'.implode(', --', $matches));
        }

        if ($matches === []) {
            $extras[] = $argument;
            continue;
        }

        $name = $matches[0];
        if (in_array($name, ['inspect', 'help'], true) && $value !== null) {
            $explicit = var_export($value, true);
            throw new InvalidArgumentException('argument --'.$name.': ignored explicit argument '.$explicit);
        }

        if ($name === 'help') {
            echo $usage."\n\nQualify Unit-attributed executable lines from the one canonical PHPUnit run.\n\n";
            echo "options:\n  -h, --help           show this help message and exit\n";
            echo "  --coverage COVERAGE\n  --tests TESTS\n  --timing TIMING\n  --output OUTPUT\n";
            echo "  --inspect            Report gaps without awarding a gate pass\n";
            exit(0);
        }

        if ($name === 'inspect') {
            $inspect = true;
            continue;
        }

        if ($value === null) {
            $value = $argv[++$i] ?? null;
            if ($value === null || !$isSeparatePath($value)) {
                throw new InvalidArgumentException('argument --'.$name.': expected one argument');
            }
        }

        $options[$name] = $value;
    }

    if ($extras !== []) {
        throw new InvalidArgumentException('unrecognized arguments: '.implode(' ', $extras));
    }
} catch (InvalidArgumentException $invalidArgumentException) {
    fwrite(STDERR, $usage."\ncheck_unit_coverage.php: error: ".$invalidArgumentException->getMessage()."\n");
    exit(2);
}

ini_set('memory_limit', '1G');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require $root.'/vendor/autoload.php';
    $identities = Enumeration::read($options['tests'], $root.'/etc/test-categories.json', $inspect);
    $report = Coverage::read($root, $options['coverage'], $identities);
    [$report['categories'], $failures] = Timing::read($options['timing'], $identities);
    $report['identity_admission'] = [
        'enumerated'                 => count($identities),
        'executed'                   => count($identities),
        'coverage_subset_verified'   => true,
        'timing_membership_verified' => true
    ];
    $report['failed_cases'] = $failures;
    $report['branches']['collected'] = $report['branches']['total'] > 0;
    $qualified = $report['covered'] === $report['total'] && $failures === 0
        && array_sum(array_column($report['categories'], 'skipped')) === 0;
    $report['result'] = $inspect ? 'inspection' : ($qualified ? 'passed' : 'failed');

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $json = preg_replace('/^( +)\1/m', '$1', $json);
    file_put_contents($options['output'], $json."\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Unit coverage qualification failed: '.$throwable->getMessage()."\n");
    exit(1);
}

printf(
    "Unit-only executable lines: %d/%d; %d source files; no exclusions\n",
    $report['covered'],
    $report['total'],
    $report['source_files']
);
if ($report['branches']['collected']) {
    printf(
        "Unit branch blocks (separate, no percentage mandate): {'covered': %d, 'total': %d, 'collected': True}\n",
        $report['branches']['covered'],
        $report['branches']['total']
    );
} else {
    echo "Branch data not collected in this line-only execution; report separate qualification and limits\n";
}

$summary = [];
foreach ($report['categories'] as $category => $counts) {
    $summary[] = sprintf(
        "'%s': {'runnable': %d, 'skipped': %d, 'milliseconds': %d}",
        $category,
        $counts['runnable'],
        $counts['skipped'],
        $counts['milliseconds']
    );
}

echo 'Expanded cases and measured per-test milliseconds (includes setup/teardown): {'.implode(', ', $summary)."}\n";
foreach ($report['files'] as $path => $data) {
    if ($data['missing'] !== []) {
        echo $path.' missing Unit lines: '.implode(', ', $data['missing'])."\n";
    }
}

exit($inspect || $qualified ? 0 : 1);
