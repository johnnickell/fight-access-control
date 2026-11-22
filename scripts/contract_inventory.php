<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\ContractInventory\Inventory;
use Fight\AccessControl\Tooling\ContractInventory\InventoryJson;

$toolRoot = dirname(__DIR__);
$usage = 'usage: contract_inventory.php [--root ROOT] [--output OUTPUT]';
$usage .= ' [--previous PREVIOUS] [--judgments JUDGMENTS] [--refresh]';
$options = ['root' => $toolRoot, 'output' => null, 'previous' => null, 'judgments' => null];
$refresh = false;
try {
    for ($i = 1; $i < $argc; $i++) {
        $argument = $argv[$i];
        if ($argument === '--help' || $argument === '-h') {
            echo $usage."\n\nDiscover PHP contracts without executing source; reconcile structural ownership only.\n\n";
            echo "  --root       Candidate checkout (defaults to this repository)\n";
            echo "  --output     Ignored candidate evidence JSON\n";
            echo "  --previous   Earlier complete candidate inventory for detailed drift\n";
            echo "  --judgments  Private owner-supplied candidate-bound evidence fragment\n";
            echo "  --refresh    Explicitly replace tracked structural fingerprints\n";
            exit(0);
        }

        if ($argument === '--refresh') {
            $refresh = true;
            continue;
        }

        [$flag, $value] = array_pad(explode('=', $argument, 2), 2, null);
        $name = substr($flag, 2);
        if (!str_starts_with($flag, '--') || !array_key_exists($name, $options)) {
            throw new InvalidArgumentException('unrecognized argument: '.$argument);
        }

        if ($value === null) {
            $value = $argv[++$i] ?? null;
            if ($value === null || str_starts_with($value, '-')) {
                throw new InvalidArgumentException('argument '.$flag.': expected one argument');
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
