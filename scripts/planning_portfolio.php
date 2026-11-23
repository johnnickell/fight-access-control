<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\Planning\Portfolio;

require __DIR__.'/Planning/bootstrap.php';

$usage = "usage: planning_portfolio.php [-h] [--write]\n";
$arguments = array_slice($argv, 1);
if (end($arguments) === '--') {
    array_pop($arguments);
}

if (array_intersect($arguments, ['-h', '--help']) !== []) {
    echo $usage, "\nGenerate and validate the EPIC -> TICKET -> TASK planning portfolio.\n\n",
        "options:\n  -h, --help  show this help message and exit\n  --write     offline projection refresh\n";
    exit(0);
}

$unknown = array_diff($arguments, ['--write']);
if ($unknown !== []) {
    fwrite(STDERR, $usage.'planning_portfolio.php: error: unrecognized arguments: '.implode(' ', $unknown).PHP_EOL);
    exit(2);
}

try {
    exit(new Portfolio(dirname(__DIR__))->run($arguments !== []));
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, "Planning validation failed:\n- ".$runtimeException->getMessage().PHP_EOL);
    exit(1);
}
