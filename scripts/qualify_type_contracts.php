<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\TypeContracts\Analyzer;
use Fight\AccessControl\Tooling\TypeContracts\ContractFailure;
use Fight\AccessControl\Tooling\TypeContracts\FixtureSuite;

require dirname(__DIR__).'/vendor/autoload.php';

$rejects = static function (string $scenario, Closure $probe, string $reason): void {
    try {
        $probe();
    } catch (ContractFailure | JsonException $failure) {
        if (str_contains($failure->getMessage(), $reason)) {
            echo "PASS $scenario\n";

            return;
        }

        throw new RuntimeException("Wrong failure for $scenario: {$failure->getMessage()}", previous: $failure);
    }

    throw new RuntimeException("Unexpected pass: $scenario");
};

$root = dirname(__DIR__);
$suite = sys_get_temp_dir().'/type-contract-qualification-'.bin2hex(random_bytes(8));
if (!mkdir($suite, 0o700)) {
    throw new RuntimeException('Cannot create isolated qualification suite');
}

try {
    $manifest = $suite.'/cases.json';
    $positive = $suite.'/positive.php';
    $negative = $suite.'/negative.php';
    file_put_contents($positive, '<?php /* Static input; never executed. */');
    copy($positive, $negative);
    $diagnostic = ['line' => 2, 'identifier' => 'phpstan.dumpType', 'message' => 'Dumped type: string'];
    $other = [...$diagnostic, 'line' => 3];
    $source = ['cases' => [
        [
            'id'          => 'positive',
            'kind'        => 'positive',
            'file'        => 'positive.php',
            'diagnostics' => [$diagnostic, $other, $other]
        ],
        ['id' => 'negative', 'kind' => 'negative', 'file' => 'negative.php', 'diagnostics' => [
            ['line' => 2, 'identifier' => 'argument.type', 'message' => 'Synthetic incompatible type']
        ]]
    ]];
    file_put_contents($manifest, json_encode($source, JSON_THROW_ON_ERROR));
    [$case] = FixtureSuite::load($suite);
    $positive = $case->file;
    $fileReport = ['errors' => 3, 'messages' => [$other, $diagnostic, $other]];
    $report = ['totals' => ['errors' => 0, 'file_errors' => 3], 'files' => [$positive => $fileReport], 'errors' => []];
    $check = static fn (array $value) => Analyzer::check($case, [1, json_encode($value, JSON_THROW_ON_ERROR), '']);
    $check($report);
    echo "PASS exact report (including reordered duplicate diagnostics)\n";
    foreach (['line' => 4, 'identifier' => 'other.error', 'message' => 'Dumped type: string '] as $field => $value) {
        $changed = $report;
        $changed['files'][$positive]['messages'][0][$field] = $value;
        $rejects("changed $field", static fn () => $check($changed), 'diagnostic mismatch');
    }

    foreach (
        [
        'wrong file'           => [[...$report, 'files' => [$negative => $fileReport]], 'diagnostic file'],
        'global error'         => [[...$report, 'errors' => ['Synthetic failure']], 'global errors'],
        'incomplete totals'    => [[...$report, 'totals' => ['errors' => 0]], 'diagnostic totals'],
        'missing messages'     => [[...$report, 'files' => [$positive => ['errors' => 3]]], 'incomplete diagnostic'],
        'wrong multiplicities' => [[...$report, 'files' => [$positive => [
            ...$fileReport, 'messages' => [$diagnostic, $diagnostic, $other]
        ]]], 'diagnostic mismatch']
        ] as $scenario => [$changed, $reason]
    ) {
        $rejects($scenario, static fn () => $check($changed), $reason);
    }

    $stdout = json_encode($report, JSON_THROW_ON_ERROR);
    $rejects('truncated JSON', static fn () => Analyzer::check($case, [1, substr($stdout, 0, -1), '']), 'Syntax error');
    $rejects('empty output', static fn () => Analyzer::check($case, [1, '', '']), 'Syntax error');
    $rejects('unexpected stderr', static fn () => Analyzer::check($case, [1, $stdout, 'warning']), 'unexpected stderr');
    foreach ([0, 2, 143] as $code) {
        $rejects(
            "analyzer exit $code",
            static fn () => Analyzer::check($case, [$code, $stdout, '']),
            'diagnostic exit 1'
        );
    }

    $positiveOnly = ['cases' => [$source['cases'][0]]];
    $duplicate = ['cases' => [...$source['cases'], $source['cases'][0]]];
    $withoutDiagnostics = $source;
    $withoutDiagnostics['cases'][0]['diagnostics'] = [];
    $invalidManifests = [
        'missing declarations' => ['{}', 'Invalid suite document'],
        'empty declarations'   => ['{"cases":[]}', 'empty fixture cases'],
        'malformed manifest'   => ['{', 'Syntax error'],
        'no negative case'     => [json_encode($positiveOnly, JSON_THROW_ON_ERROR), 'Both positive'],
        'duplicate case'       => [json_encode($duplicate, JSON_THROW_ON_ERROR), 'duplicate case'],
        'no diagnostics'       => [json_encode($withoutDiagnostics, JSON_THROW_ON_ERROR), 'missing expected']
    ];
    foreach ($invalidManifests as $scenario => [$contents, $reason]) {
        file_put_contents($manifest, $contents);
        $rejects($scenario, static fn (): array => FixtureSuite::load($suite), $reason);
    }

    file_put_contents($manifest, json_encode($source, JSON_THROW_ON_ERROR));
    $extra = $suite.'/extra.php';
    copy($positive, $extra);
    $rejects('unregistered fixture', static fn (): array => FixtureSuite::load($suite), 'Unregistered');
    unlink($extra);
    file_put_contents($negative, '');
    $rejects('empty fixture', static fn (): array => FixtureSuite::load($suite), 'empty fixture');
    unlink($negative);
    $rejects('missing fixture', static fn (): array => FixtureSuite::load($suite), 'PHP file inside the suite');
    unlink($manifest);
    $rejects('missing manifest', static fn (): array => FixtureSuite::load($suite), 'Cannot read UTF-8 input');

    foreach (
        [
        'unavailable analyzer' => [$suite.'/unavailable'],
        'signaled analyzer'    => ['/bin/sh', '-c', 'kill -TERM $$']
        ] as $scenario => $command
    ) {
        $rejects(
            $scenario,
            static fn () => Analyzer::check($case, Analyzer::capture($command, $root, $positive, 5)),
            'diagnostic exit 1'
        );
    }

    $slowCommand = ['/bin/sh', '-c', 'printf "{"; sleep 20 & wait'];
    $rejects(
        'native timeout on partial output with descendant',
        static fn (): array => Analyzer::capture($slowCommand, $root, $positive, 0.2),
        'Analyzer timed out'
    );
    echo "Static runner qualification passed.\n";
} finally {
    foreach (glob($suite.'/*') as $file) {
        unlink($file);
    }

    rmdir($suite);
}
