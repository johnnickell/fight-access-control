<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\Planning\Portfolio;

$usage = "usage: planning_portfolio.php [-h] [--write]\n";
$errorPrefix = $usage.'planning_portfolio.php: error: ';
$writing = false;
$unknown = [];
$options = true;
foreach (array_slice($argv, 1) as $argument) {
    if (!$options || $argument === '--') {
        // There are no positional arguments, even after an option separator.
        $options = false;
        $unknown[] = $argument;
        continue;
    }

    [$option, $value] = array_pad(explode('=', $argument, 2), 2, null);
    $flag = match ($option) {
        '--w', '--wr', '--wri', '--writ', '--write' => '--write',
        '-h', '--h', '--he', '--hel', '--help' => '-h/--help',
        default => null,
    };
    if ($option === '--') {
        fwrite(STDERR, $errorPrefix."ambiguous option: $argument could match --help, --write\n");
        exit(2);
    }

    if ($flag !== null && $value !== null) {
        fwrite(STDERR, $errorPrefix."argument $flag: ignored explicit argument '$value'\n");
        exit(2);
    }

    if ($flag === '--write') {
        $writing = true;
        continue;
    }

    if ($flag !== '-h/--help' && !str_starts_with($argument, '-h')) {
        $unknown[] = $argument;
        continue;
    }

    echo $usage, "\nGenerate and validate the EPIC -> TICKET -> TASK planning portfolio.\n\n",
        "options:\n  -h, --help  show this help message and exit\n  --write     offline projection refresh\n";
    exit(0);
}

if ($unknown !== []) {
    fwrite(STDERR, $errorPrefix.'unrecognized arguments: '.implode(' ', $unknown).PHP_EOL);
    exit(2);
}

require __DIR__.'/Planning/bootstrap.php';

try {
    exit(new Portfolio(dirname(__DIR__))->run($writing));
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, "Planning validation failed:\n- ".$runtimeException->getMessage().PHP_EOL);
    exit(1);
}
