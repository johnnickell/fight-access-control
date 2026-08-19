<?php

declare(strict_types=1);

final class PackageBoundary
{
    /**
     * @return list<string>
     */
    public static function inspect(string $root): array
    {
        $composerPath = $root.'/composer.json';
        if (!is_file($composerPath)) {
            return ['composer.json is required.'];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
        $violations = [];

        $expect = static function (bool $condition, string $message) use (&$violations): void {
            if (!$condition) {
                $violations[] = $message;
            }
        };

        $expect(($composer['name'] ?? null) === 'johnnickell/fight-access-control', 'Composer package name must be johnnickell/fight-access-control.');
        $expect(($composer['type'] ?? 'library') === 'library', 'Composer package type must be library.');
        $expect(($composer['require']['php'] ?? null) === '>=8.5', 'Production PHP requirement must be >=8.5.');
        $expect(isset($composer['require']['johnnickell/fight-common']), 'Production dependencies must include johnnickell/fight-common.');

        $productionDependencies = array_keys($composer['require'] ?? []);
        $unexpectedDependencies = array_values(array_diff($productionDependencies, ['php', 'johnnickell/fight-common']));
        $expect($unexpectedDependencies === [], sprintf(
            'Production dependencies may contain only PHP and Fight Common; found: %s.',
            implode(', ', $unexpectedDependencies),
        ));

        $expectedAutoload = [
            'Fight\\AccessControl\\Domain\\' => 'src/Domain',
            'Fight\\AccessControl\\Application\\' => 'src/Application',
            'Fight\\AccessControl\\Conformance\\' => 'contracts/Conformance',
        ];
        $expect(($composer['autoload']['psr-4'] ?? null) === $expectedAutoload, 'Production PSR-4 autoloading must expose only Domain, Application, and non-runtime Conformance boundaries.');

        $expect(!is_dir($root.'/src/Adapter'), 'Production Adapter code is forbidden.');
        $expect(!is_dir($root.'/src/Common'), 'Copied Fight Common source is forbidden.');

        $src = $root.'/src';
        if (!is_dir($src)) {
            return $violations;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $contents = (string) file_get_contents($file->getPathname());
            $isDomain = str_starts_with($relative, 'src/Domain/');
            $isApplication = str_starts_with($relative, 'src/Application/');

            $expect(
                $isDomain || $isApplication,
                sprintf('%s is outside the Domain and Application production roots.', $relative),
            );
            $expect(!preg_match('/namespace\s+Fight\\\\Common\\\\/', $contents), sprintf('%s copies a Fight Common namespace.', $relative));
            $expect(!preg_match('/Fight\\\\AccessControl\\\\Adapter\\\\/', $contents), sprintf('%s may not reference a production Adapter namespace.', $relative));

            if ($isDomain) {
                $expect(!preg_match('/Fight\\\\Common\\\\(?!Domain\\\\)/', $contents), sprintf('%s may use only Fight Common public Domain primitives.', $relative));
                $expect(!preg_match('/Fight\\\\AccessControl\\\\Application\\\\/', $contents), sprintf('%s has an outward Domain-to-Application dependency.', $relative));
            }

            if ($isApplication) {
                $expect(!preg_match('/Fight\\\\Common\\\\(?!Domain\\\\|Application\\\\)/', $contents), sprintf('%s may use only Fight Common public Domain or Application contracts.', $relative));
            }
        }

        return $violations;
    }
}
