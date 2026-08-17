<?php

declare(strict_types=1);

require __DIR__.'/PackageBoundary.php';

$failures = PackageBoundary::inspect(dirname(__DIR__, 2));

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: '.$failure."\n");
    }

    exit(1);
}

fwrite(STDOUT, "PASS: package boundary is framework-neutral and inward-directed.\n");
