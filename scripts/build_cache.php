<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\BuildCache;
use Fight\AccessControl\Tooling\BuildEvidence;

// Bootstrap still works before the development autoloader exists.
require __DIR__.'/BuildEvidence.php';
require __DIR__.'/BuildCache.php';

set_error_handler(static function (int $severity, string $message): never {
    throw new RuntimeException($message);
});
try {
    umask(0077);
    BuildEvidence::require(
        PHP_VERSION_ID >= 80512 && preg_match('/^8\.5\.[1-9][0-9]*$/D', PHP_VERSION) === 1,
        'Rejected PHP version: require stable PHP 8.5.12 or newer on the supported 8.5 build line.'
    );
    $root = getcwd();
    BuildEvidence::require(!is_link($root.'/var') && !is_link($root.'/var/build.lock'), 'Refusing aliased build lock');
    if (!is_dir($root.'/var')) {
        mkdir($root.'/var', 0700);
    }

    $lock = fopen($root.'/var/build.lock', 'ab');
    BuildEvidence::require(flock($lock, LOCK_EX | LOCK_NB), 'Another build owns this checkout lock');
    exit(BuildCache::run($root, array_slice($argv, 1)));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Build incomplete: '.$throwable->getMessage()."\n");
    exit(1);
}
