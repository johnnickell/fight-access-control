<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\ContractInventory\Inventory;
use Fight\AccessControl\Tooling\ContractInventory\InventoryJson;

$toolRoot = dirname(__DIR__);
$usage = 'usage: contract_inventory.php [--root ROOT] [--output OUTPUT]';
$usage .= ' [--previous PREVIOUS] [--judgments JUDGMENTS] [--refresh]';
$options = ['root' => $toolRoot, 'output' => null, 'previous' => null, 'judgments' => null];
$switches = ['help', 'refresh'];
$names = [...array_keys($options), ...$switches];
$isSeparatePathValue = static function (string $token) use ($names): bool {
    // Match argparse in the baseline runtime: lone hyphens and negative integers/decimals are values.
    $negativeNumber = '/^-(?:\p{Nd}+|\p{Nd}*\.\p{Nd}+)$/u';
    if ($token === '-' || !str_starts_with($token, '-') || preg_match($negativeNumber, $token) === 1) {
        return true;
    }

    // A token containing a space is a value only if it does not match an option first.
    if (!str_contains($token, ' ') || str_starts_with($token, '-h')) {
        return false;
    }

    $flag = explode('=', $token, 2)[0];

    return !str_starts_with($flag, '--')
        || !array_any($names, static fn (string $name): bool => str_starts_with('--'.$name, $flag));
};

$refresh = false;
try {
    for ($i = 1; $i < $argc; $i++) {
        $argument = $argv[$i];
        [$flag, $value] = array_pad(explode('=', $argument === '-h' ? '--help' : $argument, 2), 2, null);
        if (!str_starts_with($flag, '--') || $flag === '--') {
            throw new InvalidArgumentException('unrecognized argument: '.$argument);
        }

        $name = substr($flag, 2);
        if (!in_array($name, $names, true)) {
            // The Python CLI accepted unique long-option prefixes.
            $prefixMatches = array_filter($names, static fn (string $option): bool => str_starts_with($option, $name));
            $matches = array_values($prefixMatches);
            if (count($matches) > 1) {
                $choices = implode(', --', $matches);
                throw new InvalidArgumentException('ambiguous option: '.$argument.' could match --'.$choices);
            }

            $name = $matches[0] ?? throw new InvalidArgumentException('unrecognized argument: '.$argument);
        }

        if ($value !== null && in_array($name, $switches, true)) {
            $explicit = var_export($value, true);
            throw new InvalidArgumentException('argument --'.$name.': ignored explicit argument '.$explicit);
        }

        if ($name === 'help') {
            echo $usage."\n\nDiscover PHP contracts without executing source; reconcile structural ownership only.\n\n";
            echo "  --root       Candidate checkout (defaults to this repository)\n";
            echo "  --output     Ignored candidate evidence JSON\n";
            echo "  --previous   Earlier complete candidate inventory for detailed drift\n";
            echo "  --judgments  Private owner-supplied candidate-bound evidence fragment\n";
            echo "  --refresh    Explicitly replace tracked structural fingerprints\n";
            exit(0);
        }

        if ($name === 'refresh') {
            $refresh = true;
            continue;
        }

        if ($value === null) {
            $value = $argv[++$i] ?? null;
            if ($value === null || !$isSeparatePathValue($value)) {
                throw new InvalidArgumentException('argument --'.$name.': expected one argument');
            }
        }

        $options[$name] = $value;
    }
} catch (InvalidArgumentException $invalidArgumentException) {
    fwrite(STDERR, $usage."\ncontract_inventory.php: error: ".$invalidArgumentException->getMessage()."\n");
    exit(2);
}

require $toolRoot.'/vendor/autoload.php';
// A full --previous comparison also holds the earlier decoded candidate report.
ini_set('memory_limit', '512M');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $matched = new Inventory($toolRoot)->run(
        $options['root'],
        $options['output'],
        $options['previous'],
        $options['judgments'],
        $refresh
    );
    exit($matched ? 0 : 1);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Contract inventory failed: '.$throwable->getMessage()."\n");
    if ($options['output'] !== null) {
        try {
            InventoryJson::save($options['output'], (object) [
                'schema'                  => 1,
                'discovery_complete'      => false,
                'semantic_audit_complete' => false,
                'error'                   => $throwable->getMessage()
            ]);
        } catch (Throwable $writeError) {
            fwrite(STDERR, 'Could not write failure report: '.$writeError->getMessage()."\n");
        }
    }

    exit(1);
}
