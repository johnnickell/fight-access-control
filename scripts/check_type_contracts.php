<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\TypeContracts\Analyzer;
use Fight\AccessControl\Tooling\TypeContracts\ContractFailure;
use Fight\AccessControl\Tooling\TypeContracts\FixtureSuite;

$root = dirname(__DIR__);
$suite = $root.'/etc/phpstan/type-contracts';
$timeout = '120';
$usage = 'usage: check_type_contracts.php [-h] [--suite SUITE] [--timeout TIMEOUT]';

try {
    for ($index = 1; $index < count($argv); ++$index) {
        $argument = $argv[$index];
        [$option, $value] = array_pad(explode('=', $argument === '-h' ? '--help' : $argument, 2), 2, null);
        $matches = [];
        if (str_starts_with($option, '--') && strlen($option) > 2) {
            $matches = array_values(array_filter(
                ['--suite', '--timeout', '--help'],
                static fn (string $name): bool => str_starts_with($name, $option)
            ));
        }

        if (count($matches) !== 1) {
            throw new InvalidArgumentException("unrecognized argument: $argument");
        }

        if ($matches[0] === '--help' && $value === null) {
            echo $usage.PHP_EOL.PHP_EOL.'Check exact per-file PHPStan diagnostics.'.PHP_EOL;
            exit(0);
        }

        if ($matches[0] === '--help') {
            throw new InvalidArgumentException("unexpected value for $option");
        }

        if ($value === null) {
            $value = $argv[++$index] ?? null;
            if ($value === null || (str_starts_with($value, '-') && !is_numeric($value))) {
                throw new InvalidArgumentException("expected a value for $option");
            }
        }

        if ($matches[0] === '--suite') {
            $suite = $value;
        } else {
            if (!is_numeric($value) && preg_match('/^[+-]?(?:inf(?:inity)?|nan)$/iD', trim($value)) !== 1) {
                throw new InvalidArgumentException("invalid timeout: $value");
            }

            $timeout = $value;
        }
    }
} catch (InvalidArgumentException $invalidArgumentException) {
    fwrite(STDERR, $usage.PHP_EOL.'check_type_contracts.php: error: '.$invalidArgumentException->getMessage().PHP_EOL);
    exit(2);
}

try {
    foreach (['vendor/bin/phpstan', 'vendor/autoload.php', 'phpstan-type-contracts.neon'] as $input) {
        if (!is_file($root.'/'.$input)) {
            throw new RuntimeException('Missing analyzer/dependency/config input: '.$input);
        }
    }

    require $root.'/vendor/autoload.php';
    $seconds = (float) $timeout;
    FixtureSuite::require(is_finite($seconds) && $seconds > 0, 'Analyzer timeout must be finite and positive');
    $suitePath = realpath($suite === '' ? '.' : $suite);
    FixtureSuite::require($suitePath !== false && is_dir($suitePath), 'Missing suite directory: '.$suite);
    $cases = FixtureSuite::load($suitePath);
    $diagnostics = 0;
    foreach ($cases as $case) {
        $result = Analyzer::run($root, $case->file, $seconds);
        try {
            Analyzer::check($case, $result);
        } catch (ContractFailure | JsonException $failure) {
            throw new ContractFailure(
                $failure->getMessage()."\nAnalyzer stdout:\n".$result[1]."\nAnalyzer stderr:\n".$result[2],
                previous: $failure
            );
        }

        $count = count($case->diagnostics);
        $diagnostics += $count;
        printf("[type-contracts] PASS %s (%s, %d diagnostics)\n", $case->id, $case->kind, $count);
        flush();
    }

    printf("[type-contracts] Passed %d cases; %d exact diagnostics\n", count($cases), $diagnostics);
} catch (RuntimeException | JsonException | ValueError $failure) {
    fwrite(STDERR, '[type-contracts] FAIL '.$failure->getMessage().PHP_EOL);
    exit(1);
}
