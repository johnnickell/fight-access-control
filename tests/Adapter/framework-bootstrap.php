<?php

declare(strict_types=1);

// Native framework fixtures share class dependencies, not an application bootstrap.
// Defer Laravel's conflicting global helpers until its isolated test process selects it.
// This file must run before Composer, via the test launcher's auto_prepend_file option.
/** @var array<string, string> $autoloadFiles */
$autoloadFiles = require __DIR__.'/../../vendor/composer/autoload_files.php';
foreach ($autoloadFiles as $identifier => $file) {
    if (
        str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php') ||
        str_ends_with($file, '/laravel/framework/src/Illuminate/Support/helpers.php')
    ) {
        $GLOBALS['__composer_autoload_files'][$identifier] = true;
    }
}

unset($autoloadFiles, $identifier, $file);
