<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\Planning\Archive;

require __DIR__.'/Planning/bootstrap.php';
require __DIR__.'/Planning/Archive.php';

$usage = <<<'USAGE'
    usage: archive_planning.php [-h] [--apply]
                               {tasks,tickets,epics,wayfinder} identifiers [identifiers ...]

    USAGE;
$kinds = ['tasks', 'tickets', 'epics', 'wayfinder'];
$choices = "'tasks', 'tickets', 'epics', 'wayfinder'";
$argumentError = static function (string $message) use ($usage): never {
    fwrite(STDERR, $usage."archive_planning.php: error: $message\n");
    exit(2);
};

$apply = false;
$options = true;
$identifiersEnded = false;
$positional = [];
$unknown = [];
foreach (array_slice($argv, 1) as $argument) {
    [$name, $value] = array_pad(explode('=', $argument, 2), 2, null);
    $flag = $options ? match (true) {
        $name === '--' && $value !== null => $argumentError(
            "ambiguous option: $argument could match --help, --apply"
        ),
        in_array($name, ['--a', '--ap', '--app', '--appl', '--apply'], true) => '--apply',
        in_array($name, ['--h', '--he', '--hel', '--help'], true),
        str_starts_with($name, '-h') => '-h/--help',
        default => null,
    } : null;
    // A short help cluster exits on its first -h; an attached value on a help spelling is invalid.
    $shortHelpCluster = str_starts_with($name, '-h') && rtrim($name, 'h') !== '-';
    if ($flag !== null && $value !== null && !$shortHelpCluster) {
        $argumentError("argument $flag: ignored explicit argument ".var_export($value, true));
    }

    if ($flag === '-h/--help') {
        echo $usage, "\nArchive explicitly selected terminal planning records and repair local Markdown links.\n\n",
            "positional arguments:\n  {tasks,tickets,epics,wayfinder}\n  identifiers\n\n",
            "options:\n  -h, --help  show this help message and exit\n",
            "  --apply     perform the validated archive move\n";
        exit(0);
    }

    $unknownOption = strlen($argument) > 1 && str_starts_with($argument, '-')
        && !str_contains($argument, ' ')
        && preg_match('/^-(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $argument) !== 1;
    if ($options && $argument === '--') {
        $options = false;
        if ($identifiersEnded) {
            $unknown[] = $argument;
        }
    } elseif ($options && ($flag !== null || $unknownOption)) {
        // Once an option follows at least one identifier, the positional group cannot resume.
        $identifiersEnded = count($positional) > 1;
        if ($flag === '--apply') {
            $apply = true;
        } else {
            $unknown[] = $argument;
        }
    } elseif ($identifiersEnded) {
        $unknown[] = $argument;
    } else {
        if ($positional === [] && !in_array($argument, $kinds, true)) {
            $argumentError('argument kind: invalid choice: '.var_export($argument, true)." (choose from $choices)");
        }

        $positional[] = $argument;
    }
}

$kind = array_shift($positional);
$error = match (true) {
    $kind === null => 'the following arguments are required: kind, identifiers',
    $positional === [] => 'the following arguments are required: identifiers',
    $unknown !== [] => 'unrecognized arguments: '.implode(' ', $unknown),
    default => null,
};
if ($error !== null) {
    $argumentError($error);
}

try {
    exit(new Archive(dirname(__DIR__))->run($kind, $positional, $apply));
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, 'Archive failed: '.$runtimeException->getMessage().PHP_EOL);
    exit(1);
}
