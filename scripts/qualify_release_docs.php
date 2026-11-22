<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\ReleaseDocs;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

require __DIR__.'/ReleaseDocs/metadata.php';

/** Writes a disposable metadata fixture with an older release and unrelated versions */
$writeFixture = static function (string $root, string $status): void {
    $repository = REPOSITORY;
    file_put_contents($root.'/README.md', <<<MD
# Package
<!-- release:start -->
**Release: [v0.7.0]($repository/releases/tag/v0.7.0)**
**Status: $status.**
Requires **PHP 8.5+** and **Fight Common ^1.3**.
```bash
composer require johnnickell/fight-access-control:"~0.7.0"
```
[Release overview](docs/release-0.7.0.md)
[Tagged documentation]($repository/tree/v0.7.0/docs)
<!-- release:end -->
Historical v0.1.0 and independent protocol version 2 remain unchanged.
MD);
    file_put_contents($root.'/CHANGELOG.md', <<<MD
# Changelog
## [Unreleased]
## [0.7.0] - 2026-10-20
## [0.6.0] - 2026-10-19
[Unreleased]: $repository/compare/v0.7.0...HEAD
[0.7.0]: $repository/compare/v0.6.0...v0.7.0
MD);
    file_put_contents($root.'/docs/release-0.7.0.md', "# v0.7.0 release and integration overview\n");
    file_put_contents($root.'/composer.json', <<<'JSON'
{"name":"johnnickell/fight-access-control","require":{"php":">=8.5","johnnickell/fight-common":"^1.3"}}
JSON);
};

/** Records fixture contents and paths before running the checker */
$snapshot = static function (string $root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $path => $info) {
        $files[$path] = $info->isDir() ? null : file_get_contents($path);
    }

    ksort($files);

    return $files;
};

/** Runs the production CLI against a fixture without Git, Composer or Docker */
$checkFixture = static function (
    string $root,
    string $version,
    int $expectedExit,
    array $expectedOutput
) use (
    $snapshot
): void {
    $before = $snapshot($root);
    $process = proc_open(
        [PHP_BINARY, '-n', $root.'/scripts/check_release_docs.php', $version],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the release documentation checker.');
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    if ($exit !== $expectedExit || $output !== implode("\n", $expectedOutput)."\n") {
        throw new RuntimeException("Unexpected checker result for v$version (exit $exit): $output");
    }

    if ($before !== $snapshot($root)) {
        throw new RuntimeException('Checker changed the fixture.');
    }
};

/** Qualifies a diagnostic and compares the CLI with the shared validator */
$checkFailure = static function (string $root, string $version, string $diagnostic) use ($checkFixture): void {
    $errors = validate($root, $version);
    if (!str_contains(implode("\n", $errors), $diagnostic)) {
        throw new RuntimeException("Missing diagnostic: $diagnostic");
    }

    $checkFixture($root, $version, 3, array_map(static fn (string $error): string => "release docs: $error", $errors));
};

$root = realpath(sys_get_temp_dir()).'/access-control-release-docs-'.bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
    fwrite(STDERR, "Cannot create the release documentation fixture.\n");
    exit(1);
}

$exit = 0;
try {
    mkdir($root.'/docs');
    mkdir($root.'/scripts');
    mkdir($root.'/scripts/ReleaseDocs');
    copy(__DIR__.'/check_release_docs.php', $root.'/scripts/check_release_docs.php');
    copy(__DIR__.'/ReleaseDocs/metadata.php', $root.'/scripts/ReleaseDocs/metadata.php');
    foreach (['published', 'prepared (not yet published)'] as $status) {
        $writeFixture($root, $status);
        $checkFixture($root, '0.7.0', 0, [
            'Release documentation is consistent for v0.7.0; publication is not verified.'
        ]);
    }

    $cases = [
        ['README.md', '[v0.7.0]', '[v0.6.0]', 'release label/tag'],
        ['README.md', 'releases/tag/v0.7.0', 'releases/tag/v0.6.0', 'release label/tag'],
        ['README.md', '"~0.7.0"', '"~0.6.0"', 'install command'],
        ['README.md', 'release-0.7.0.md', 'release-0.6.0.md', 'release overview'],
        ['README.md', 'tree/v0.7.0/docs', 'tree/v0.6.0/docs', 'tagged documentation'],
        ['README.md', '**Status: published.**', '**Status: ready.**', 'status must'],
        ['README.md', '<!-- release:end -->', '', 'release:start/release:end'],
        ['README.md', '<!-- release:start -->', "<!-- release:start -->\n<!-- release:start -->",
            'release:start/release:end'],
        ['README.md', '**PHP 8.5+**', '**PHP 8.4+**', 'runtime requirements'],
        ['README.md', '**Fight Common ^1.3**', '**Fight Common ^1.2**', 'runtime requirements'],
        ['CHANGELOG.md', '## [Unreleased]', '## [0.8.0] - 2026-10-21', 'first and unique'],
        ['CHANGELOG.md', '2026-10-20', '2026-02-30', 'valid YYYY-MM-DD'],
        ['CHANGELOG.md', '## [0.6.0]', '## [0.7.0]', 'first and unique'],
        ['CHANGELOG.md', 'compare/v0.7.0...HEAD', 'compare/v0.6.0...HEAD', '[Unreleased] link'],
        ['CHANGELOG.md', 'compare/v0.6.0...v0.7.0', 'compare/v0.5.0...v0.7.0', '[0.7.0] link'],
        ['docs/release-0.7.0.md', '# v0.7.0', '# v0.6.0', 'overview title'],
        ['composer.json', '{"name":', '{"version":"0.7.0","name":', 'omit version'],
        ['composer.json', '{"name":', '{"name"', 'invalid JSON']
    ];
    foreach ($cases as [$name, $old, $new, $diagnostic]) {
        $writeFixture($root, 'published');
        $path = $root.'/'.$name;
        $changed = str_replace($old, $new, file_get_contents($path), $count);
        if ($count === 0) {
            throw new RuntimeException("Fixture mutation did not match $name: $old");
        }

        file_put_contents($path, $changed);
        $checkFailure($root, '0.7.0', $diagnostic);
    }

    foreach (['README.md', 'CHANGELOG.md', 'composer.json', 'docs/release-0.7.0.md'] as $name) {
        $writeFixture($root, 'published');
        unlink($root.'/'.$name);
        $checkFailure($root, '0.7.0', "$name: cannot read");
    }

    $writeFixture($root, 'published');
    foreach (['v0.7.0', '00.7.0', '0.7', '0.7.0-rc.1', '../0.7.0', '０.7.0'] as $version) {
        $checkFixture($root, $version, 3, [
            'release docs: Version must be X.Y.Z without a v prefix or leading zeroes.'
        ]);
    }

    $checkFailure($root, '0.6.0', 'first and unique');
    echo "Release documentation qualification passed: 31 read-only CLI cases.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Release documentation qualification failed: '.$throwable->getMessage()."\n");
    $exit = 1;
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path => $info) {
        $info->isDir() ? rmdir($path) : unlink($path);
    }

    rmdir($root);
}

exit($exit);
