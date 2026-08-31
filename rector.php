<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withCache(__DIR__.'/var/cache/rector')
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests/Tooling',
        __DIR__.'/tests/Domain',
        __DIR__.'/tests/Application',
        __DIR__.'/scripts',
    ])
    ->withPhpSets(php84: true)
    ->withImportNames(removeUnusedImports: true)
    ->withTypeCoverageLevel(8)
    ->withDeadCodeLevel(8)
    ->withCodeQualityLevel(8)
    ->withPreparedSets(
        codingStyle: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    );
