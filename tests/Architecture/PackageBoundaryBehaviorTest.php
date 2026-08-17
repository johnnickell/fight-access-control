<?php

declare(strict_types=1);

require __DIR__.'/PackageBoundary.php';

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$fixture = sys_get_temp_dir().'/fight-access-control-boundary-'.bin2hex(random_bytes(8));

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) {
        if (is_file($path)) {
            unlink($path);
        }

        return;
    }

    foreach (new FilesystemIterator($path) as $item) {
        $remove($item->getPathname());
    }

    rmdir($path);
};

$writeFixture = static function (array $metadata, array $files = []) use ($fixture, $remove): void {
    $remove($fixture);
    mkdir($fixture.'/src/Domain', recursive: true);
    mkdir($fixture.'/src/Application', recursive: true);
    file_put_contents($fixture.'/composer.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    foreach ($files as $relative => $contents) {
        $path = $fixture.'/'.$relative;
        if (str_ends_with($relative, '/')) {
            mkdir($path, recursive: true);
            continue;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents($path, $contents);
    }
};

$expectViolation = static function (string $message, array $metadata, array $files = []) use ($writeFixture, $fixture): void {
    $writeFixture($metadata, $files);
    $violations = PackageBoundary::inspect($fixture);

    if (!in_array($message, $violations, true)) {
        throw new RuntimeException(sprintf("Expected violation was not reported: %s\nReported: %s", $message, implode(' | ', $violations)));
    }
};

$expectAccepted = static function (array $metadata, array $files) use ($writeFixture, $fixture): void {
    $writeFixture($metadata, $files);
    $violations = PackageBoundary::inspect($fixture);

    if ($violations !== []) {
        throw new RuntimeException(sprintf('Expected fixture to be accepted; reported: %s', implode(' | ', $violations)));
    }
};

try {
    $frameworkMetadata = $composer;
    $frameworkMetadata['require']['symfony/http-kernel'] = '^8.0';
    $expectViolation(
        'Production dependencies may contain only PHP and Fight Common; found: symfony/http-kernel.',
        $frameworkMetadata,
    );

    $expectViolation('Production Adapter code is forbidden.', $composer, ['src/Adapter/' => '']);
    $expectViolation('Copied Fight Common source is forbidden.', $composer, ['src/Common/' => '']);
    $expectViolation(
        'src/Infrastructure/Connection.php is outside the Domain and Application production roots.',
        $composer,
        ['src/Infrastructure/Connection.php' => "<?php\nnamespace Fight\\AccessControl\\Infrastructure;\n"],
    );
    $expectViolation(
        'src/Domain/Copied.php copies a Fight Common namespace.',
        $composer,
        ['src/Domain/Copied.php' => "<?php\nnamespace Fight\\Common\\Domain;\n"],
    );
    $expectViolation(
        'src/Domain/LeakyDomain.php has an outward Domain-to-Application dependency.',
        $composer,
        ['src/Domain/LeakyDomain.php' => "<?php\nnamespace Fight\\AccessControl\\Domain;\nfinal class LeakyDomain { public function leak(): Fight\\AccessControl\\Application\\UseCase {} }\n"],
    );
    $expectViolation(
        'src/Domain/LeakyCommonApplication.php may use only Fight Common public Domain primitives.',
        $composer,
        ['src/Domain/LeakyCommonApplication.php' => "<?php\nnamespace Fight\\AccessControl\\Domain;\nuse Fight\\Common\\Application\\Bus\\CommandBus;\n"],
    );
    $expectViolation(
        'src/Application/LeakyApplication.php may use only Fight Common public Domain or Application contracts.',
        $composer,
        ['src/Application/LeakyApplication.php' => "<?php\nnamespace Fight\\AccessControl\\Application;\nuse Fight\\Common\\Adapter\\Repository;\n"],
    );
    $expectAccepted(
        $composer,
        [
            'src/Domain/AllowedCommonDomain.php' => "<?php\nnamespace Fight\\AccessControl\\Domain;\nuse Fight\\Common\\Domain\\Identity\\Id;\n",
            'src/Application/AllowedCommonContracts.php' => "<?php\nnamespace Fight\\AccessControl\\Application;\nuse Fight\\Common\\Domain\\Identity\\Id;\nuse Fight\\Common\\Application\\Bus\\CommandBus;\n",
        ],
    );
} finally {
    $remove($fixture);
}

fwrite(STDOUT, "PASS: package boundary guard rejects forbidden dependencies and source directions.\n");
