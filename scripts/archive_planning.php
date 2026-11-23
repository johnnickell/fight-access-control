<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\Planning\Archive;

require __DIR__.'/Planning/bootstrap.php';
require __DIR__.'/Planning/Archive.php';

$usage = <<<'USAGE'
    usage: archive_planning.php [-h] [--apply]
                               {tasks,tickets,epics,wayfinder} identifiers [identifiers ...]

    USAGE;
$apply = false;
$options = true;
$positional = [];
$unknown = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($options && in_array($argument, ['-h', '--help'], true)) {
        echo $usage, "\nArchive explicitly selected terminal planning records and repair local Markdown links.\n\n",
            "positional arguments:\n  {tasks,tickets,epics,wayfinder}\n  identifiers\n\n",
            "options:\n  -h, --help  show this help message and exit\n",
            "  --apply     perform the validated archive move\n";
        exit(0);
    }

    if ($options && $argument === '--apply') {
        $apply = true;
    } elseif ($options && $argument === '--') {
        $options = false;
    } elseif ($options && strlen($argument) > 1 && str_starts_with($argument, '-')) {
        $unknown[] = $argument;
    } else {
        $positional[] = $argument;
    }
}

$kind = array_shift($positional);
$kinds = ['tasks', 'tickets', 'epics', 'wayfinder'];
$choices = "'tasks', 'tickets', 'epics', 'wayfinder'";
$invalidKind = 'argument kind: invalid choice: '.var_export($kind, true);
$error = match (true) {
    $kind === null => 'the following arguments are required: kind, identifiers',
    !in_array($kind, $kinds, true) => "$invalidKind (choose from $choices)",
    $positional === [] => 'the following arguments are required: identifiers',
    $unknown !== [] => 'unrecognized arguments: '.implode(' ', $unknown),
    default => null,
};
if ($error !== null) {
    fwrite(STDERR, $usage."archive_planning.php: error: $error\n");
    exit(2);
}

try {
    exit(new Archive(dirname(__DIR__))->run($kind, $positional, $apply));
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, 'Archive failed: '.$runtimeException->getMessage().PHP_EOL);
    exit(1);
}
