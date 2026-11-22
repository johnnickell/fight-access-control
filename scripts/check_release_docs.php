<?php

declare(strict_types=1);

use function Fight\AccessControl\Tooling\ReleaseDocs\validate;

require __DIR__.'/ReleaseDocs/metadata.php';

if ($argc === 2 && in_array($argv[1], ['-h', '--help'], true)) {
    echo "usage: check_release_docs.php [-h] version\n\nRead-only release metadata preflight.\n";
    exit(0);
}

if ($argc !== 2) {
    fwrite(STDERR, "usage: check_release_docs.php [-h] version\n");
    exit(2);
}

$version = $argv[1];
$errors = validate(dirname(__DIR__), $version);
if ($errors !== []) {
    foreach ($errors as $error) {
        echo "release docs: $error\n";
    }

    exit(3);
}

echo "Release documentation is consistent for v$version; publication is not verified.\n";
