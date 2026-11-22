<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\UnitCoverage\Coverage;
use Fight\AccessControl\Tooling\UnitCoverage\Enumeration;
use Fight\AccessControl\Tooling\UnitCoverage\Timing;

$usage = 'Usage: php scripts/check_unit_coverage.php [--coverage PATH] [--tests PATH] [--timing PATH] [--output PATH]';
if ($argc === 2 && in_array($argv[1], ['-h', '--help'], true)) {
    echo $usage."\n";
    exit(0);
}

$options = [
    '--coverage' => 'var/reports/coverage/coverage.php',
    '--tests'    => 'var/reports/coverage/tests.xml',
    '--timing'   => 'var/reports/coverage/teamcity.log',
    '--output'   => 'var/reports/coverage/unit.json'
];
for ($i = 1; $i < $argc; ++$i) {
    [$flag, $path] = array_pad(explode('=', $argv[$i], 2), 2, null);
    $path ??= $argv[++$i] ?? '';
    if (!isset($options[$flag]) || $path === '' || str_starts_with($path, '--')) {
        fwrite(STDERR, $usage."\nInvalid option or missing path: ".$flag."\n");
        exit(2);
    }

    $options[$flag] = $path;
}

ini_set('memory_limit', '1G');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $root = dirname(__DIR__);
    require $root.'/vendor/autoload.php';
    $identities = Enumeration::read($options['--tests'], $root.'/etc/test-categories.json');
    $report = Coverage::read($root, $options['--coverage'], $identities);
    [$report['categories'], $report['failed_cases']] = Timing::read($options['--timing'], $identities);
    $qualified = $report['covered'] === $report['total'] && $report['failed_cases'] === 0
        && array_sum(array_column($report['categories'], 'skipped')) === 0;
    $report['result'] = $qualified ? 'passed' : 'failed';

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    file_put_contents($options['--output'], json_encode($report, $flags)."\n");
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
if ($report['branches']['total'] > 0) {
    printf(
        "Unit branch blocks (reported separately): %d/%d\n",
        $report['branches']['covered'],
        $report['branches']['total']
    );
} else {
    echo "Branch data not collected\n";
}

foreach ($report['categories'] as $category => $counts) {
    printf(
        "%s: %d runnable, %d skipped, %d ms\n",
        $category,
        $counts['runnable'],
        $counts['skipped'],
        $counts['milliseconds']
    );
}

foreach ($report['files'] as $path => $data) {
    if ($data['missing'] !== []) {
        echo $path.' missing Unit lines: '.implode(', ', $data['missing'])."\n";
    }
}

exit($qualified ? 0 : 1);
