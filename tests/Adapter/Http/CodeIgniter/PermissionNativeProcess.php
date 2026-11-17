<?php

declare(strict_types=1);

use Fight\Test\AccessControl\Adapter\Http\CodeIgniter\PermissionNativeScenario;
use PHPUnit\Framework\Assert;

// Match CI4's production boot ordering: native Common precedes Composer's optional helpers.
require $argv[4];
require getcwd().'/vendor/autoload.php';

try {
    $scenario = new PermissionNativeScenario();
    $scenario->run($argv[1], $argv[2] === '1', (int) $argv[3]);
    echo json_encode(['environment' => ENVIRONMENT, 'assertions' => Assert::getCount()], JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    // Fixture assertions contain synthetic diagnostics only; never a consumer response path.
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage()."\n".$throwable->getTraceAsString());
    exit(1);
}
