<?php

declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__, 2);
$root = realpath($root);
if (!is_string($root)) {
    fwrite(STDERR, "FAIL: production install root does not exist.\n");
    exit(1);
}
$autoload = $root.'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "FAIL: production Composer autoload is not installed.\n");
    exit(1);
}

$loader = require $autoload;
if (!$loader instanceof Composer\Autoload\ClassLoader) {
    fwrite(STDERR, "FAIL: Composer did not return its production class loader.\n");
    exit(1);
}
if (!$loader->isClassMapAuthoritative()) {
    fwrite(STDERR, "FAIL: production Composer autoload is not authoritative.\n");
    exit(1);
}

$prefixes = $loader->getPrefixesPsr4();
$expected = [
    'Fight\\AccessControl\\Domain\\' => [$root.'/src/Domain'],
    'Fight\\AccessControl\\Application\\' => [$root.'/src/Application'],
    'Fight\\AccessControl\\Conformance\\' => [$root.'/contracts/Conformance'],
];

foreach ($expected as $namespace => $paths) {
    $actualPaths = array_map(static fn (string $path): string|false => realpath($path), $prefixes[$namespace] ?? []);
    $expectedPaths = array_map(static fn (string $path): string|false => realpath($path), $paths);
    if ($actualPaths !== $expectedPaths) {
        fwrite(STDERR, sprintf("FAIL: production autoload mapping for %s is not authoritative.\n", $namespace));
        exit(1);
    }
}

$accessControlPrefixes = array_filter(
    array_keys($prefixes),
    static fn (string $namespace): bool => str_starts_with($namespace, 'Fight\\AccessControl\\'),
);
sort($accessControlPrefixes);
$expectedPrefixes = array_keys($expected);
sort($expectedPrefixes);
if ($accessControlPrefixes !== $expectedPrefixes) {
    fwrite(STDERR, "FAIL: production autoload exposes an AccessControl namespace outside Domain, Application, or Conformance.\n");
    exit(1);
}

if (!trait_exists(Fight\AccessControl\Conformance\Invitation\InvitationConformance::class)) {
    fwrite(STDERR, "FAIL: production install does not autoload the public invitation conformance suite.\n");
    exit(1);
}

if (!interface_exists(Fight\Common\Application\Repository\UnitOfWork::class)) {
    fwrite(STDERR, "FAIL: a Fight Common public Application contract is not autoloadable.\n");
    exit(1);
}

$commonContract = new ReflectionClass(Fight\Common\Application\Repository\UnitOfWork::class);
$commonSource = $commonContract->getFileName();
if (!is_string($commonSource) || !str_contains($commonSource, '/vendor/johnnickell/fight-common/src/')) {
    fwrite(STDERR, "FAIL: Fight Common public contracts must come from the Composer dependency.\n");
    exit(1);
}

$packages = Composer\InstalledVersions::getInstalledPackages();
if (!in_array('johnnickell/fight-common', $packages, true)) {
    fwrite(STDERR, "FAIL: production install does not contain Fight Common.\n");
    exit(1);
}

$frameworkPrefixes = ['symfony/', 'illuminate/', 'laravel/', 'yiisoft/', 'codeigniter4/', 'slim/'];
foreach ($packages as $package) {
    foreach ($frameworkPrefixes as $prefix) {
        if (str_starts_with($package, $prefix)) {
            fwrite(STDERR, sprintf("FAIL: production resolution contains framework package %s.\n", $package));
            exit(1);
        }
    }
}

$package = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (array_keys($package['require-dev'] ?? []) as $devPackage) {
    if (in_array($devPackage, $packages, true)) {
        fwrite(STDERR, sprintf("FAIL: production install contains development package %s.\n", $devPackage));
        exit(1);
    }
}

fwrite(STDOUT, "PASS: production Composer install exposes only the package boundaries and contains no framework package.\n");
