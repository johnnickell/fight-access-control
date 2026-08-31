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
    static fn (string $namespace): bool => str_starts_with($namespace, 'Fight\\AccessControl\\')
);
sort($accessControlPrefixes);
$expectedPrefixes = array_keys($expected);
sort($expectedPrefixes);
if ($accessControlPrefixes !== $expectedPrefixes) {
    fwrite(STDERR, "FAIL: production autoload exposes an AccessControl namespace outside Domain or Application.\n");
    exit(1);
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, sprintf("FAIL: %s\n", $message));
        exit(1);
    }
};

$authenticatedPrincipalType = Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType::class;
$publicTypes = [
    Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext::class,
    Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority::class,
    $authenticatedPrincipalType,
    Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal::class,
    Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal::class,
    Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission::class,
];
foreach ($publicTypes as $publicType) {
    $expect(
        interface_exists($publicType) || class_exists($publicType) || enum_exists($publicType),
        sprintf('supported public authority type %s is not production-autoloadable.', $publicType)
    );
}

$securityContext = new ReflectionClass(
    Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext::class
);
$securityContextConstructor = $securityContext->getConstructor();
$expect($securityContext->isFinal(), 'SecurityContext must remain final.');
$expect($securityContextConstructor instanceof ReflectionMethod, 'SecurityContext must define a constructor.');
$expect(
    $securityContextConstructor->getNumberOfParameters() === 1
        && $securityContextConstructor->getNumberOfRequiredParameters() === 1,
    'SecurityContext must require exactly one authenticated authority.'
);
$securityContextAuthority = $securityContextConstructor->getParameters()[0] ?? null;
$securityContextAuthorityType = $securityContextAuthority?->getType();
$expect(
    $securityContextAuthorityType instanceof ReflectionNamedType
        && $securityContextAuthorityType->getName()
            === Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority::class
        && !$securityContextAuthority->isVariadic(),
    'SecurityContext must accept one non-variadic AuthenticatedAuthority.'
);

$authority = new ReflectionClass(Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority::class);
$authorityType = $authority->getMethod('getType')->getReturnType();
$expect($authority->isInterface(), 'AuthenticatedAuthority must remain a public interface.');
$expect($authority->hasMethod('getType'), 'AuthenticatedAuthority must expose getType().');
$expect(
    $authorityType instanceof ReflectionNamedType && $authorityType->getName() === $authenticatedPrincipalType,
    'AuthenticatedAuthority::getType() must return AuthenticatedPrincipalType.'
);
$expect($authority->hasMethod('hasPermission'), 'AuthenticatedAuthority must expose hasPermission().');
$expect($authority->hasMethod('hasRole'), 'AuthenticatedAuthority must expose hasRole().');

$principalType = new ReflectionEnum($authenticatedPrincipalType);
$principalTypeValues = [];
foreach ($principalType->getCases() as $case) {
    $principalTypeValues[$case->getName()] = $case->getBackingValue();
}
$expect(
    $principalTypeValues === ['USER' => 'user', 'AGENT' => 'agent'],
    'AuthenticatedPrincipalType must retain only stable USER and AGENT values.'
);

$permission = new Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission(
    Fight\AccessControl\Domain\AccessControl\Permission\PermissionId::fromString(
        '018f0000-0000-7000-8000-000000000001'
    ),
    Fight\AccessControl\Domain\AccessControl\Permission\PermissionName::fromString('READ_PERMISSION')
);
$principalPermission = new ReflectionClass($permission);
$permissionIdType = $principalPermission->getMethod('getPermissionId')->getReturnType();
$permissionNameType = $principalPermission->getMethod('getName')->getReturnType();
$expect(
    $permission->toArray() === [
        'permission_id' => '018f0000-0000-7000-8000-000000000001',
        'name' => 'READ_PERMISSION',
    ],
    'PrincipalPermission must expose only its safe permission_id and name representation.'
);
$expect(
    $permissionIdType instanceof ReflectionNamedType
        && $permissionIdType->getName() === Fight\AccessControl\Domain\AccessControl\Permission\PermissionId::class,
    'PrincipalPermission::getPermissionId() must expose PermissionId.'
);
$expect(
    $permissionNameType instanceof ReflectionNamedType
        && $permissionNameType->getName() === Fight\AccessControl\Domain\AccessControl\Permission\PermissionName::class,
    'PrincipalPermission::getName() must expose PermissionName.'
);

foreach (
    [
    Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal::class,
    Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal::class,
    ] as $principalType
) {
    $principal = new ReflectionClass($principalType);
    $expect(
        $principal->implementsInterface(
            Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority::class
        ),
        sprintf('%s must implement AuthenticatedAuthority.', $principalType)
    );
    $expect($principal->hasMethod('getType'), sprintf('%s must expose getType().', $principalType));
    $expect($principal->hasMethod('getPermissions'), sprintf('%s must expose getPermissions().', $principalType));
    $expect($principal->hasMethod('hasPermission'), sprintf('%s must expose hasPermission().', $principalType));
    $expect($principal->hasMethod('hasRole'), sprintf('%s must expose hasRole().', $principalType));
}

foreach (
    [
    'Fight\\AccessControl\\Application\\AccessControl\\Authorization\\Service\\CurrentSecurityContext',
    'Fight\\AccessControl\\Domain\\AccessControl\\Authorization\\Exception\\CurrentSecurityContextException',
    str_replace('/', '\\', 'Fight/AccessControl/Domain/AccessControl/Agent/AgentPrincipalPermission'),
    str_replace('/', '\\', 'Fight/AccessControl/Domain/AccessControl/Agent/Query/AgentPermissionView'),
    ] as $removedType
) {
    $expect(
        !class_exists($removedType),
        sprintf('removed authority type %s must not have a compatibility alias.', $removedType)
    );
}

foreach (
    [
    'Fight\\AccessControl\\Application\\AccessControl\\Authorization\\Service\\ExactPermissionResolver',
    'Fight\\AccessControl\\Application\\AccessControl\\Authorization\\Service\\ExactPermissionResolutionException',
    'Fight\\AccessControl\\Application\\AccessControl\\Authorization\\Service\\AuthoritativePrincipalResolver',
    ] as $internalType
) {
    $internal = new ReflectionClass($internalType);
    $expect($internal->isFinal(), sprintf('%s must be final.', $internalType));
    $expect(
        str_contains((string) $internal->getDocComment(), '@internal'),
        sprintf('%s must be documented as internal.', $internalType)
    );
}

$currentAgentPrincipalProvider = new ReflectionClass(
    Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider::class
);
$expect(
    !str_contains((string) $currentAgentPrincipalProvider->getDocComment(), '@internal'),
    'CurrentAgentPrincipalProvider must remain a supported public service.'
);
foreach (
    [
    Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler::class,
    Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler::class,
    ] as $publicHandler
) {
    $handler = new ReflectionClass($publicHandler);
    $expect(
        !str_contains((string) $handler->getDocComment(), '@internal'),
        sprintf('%s must remain a supported public handler.', $publicHandler)
    );
}
$expect(!is_dir($root.'/src/Adapter'), 'production source must not contain an Adapter boundary.');

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

fwrite(
    STDOUT,
    "PASS: production Composer install exposes only the package boundaries and contains no framework package.\n"
);
