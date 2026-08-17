<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GovernanceContractTest extends TestCase
{
    private string $root;

    public function test_that_public_source_is_licensed_under_mit(): void
    {
        $composer = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('MIT', $composer['license'] ?? null);
        self::assertStringContainsString('Permission is hereby granted', $this->read('LICENSE'));
    }

    public function test_that_maintainer_guidance_covers_governance_and_isolation(): void
    {
        $documents = implode("\n", [
            $this->read('README.md'),
            $this->read('CONTRIBUTING.md'),
            $this->read('SECURITY.md'),
            $this->read('CHANGELOG.md')
        ]);

        foreach (
            [
                'public-source incubation',
                'security',
                'Git Flow',
                '.runs/',
                './bin/build',
                'public visibility',
                'commit',
                'tag',
                'Packagist',
                'release'
            ] as $requiredGuidance
        ) {
            self::assertStringContainsStringIgnoringCase($requiredGuidance, $documents);
        }
    }

    public function test_that_publication_effects_are_documented_as_independent_approvals(): void
    {
        $readme = $this->read('README.md');

        foreach (
            [
                'Public repository visibility',
                'Commit creation',
                'Version tag creation',
                'Packagist publication',
                'Release publication'
            ] as $effect
        ) {
            self::assertStringContainsString($effect, $readme);
        }

        self::assertStringContainsString('separate approval', $readme);
    }

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root.'/'.$path);
        self::assertNotFalse($contents, 'Unable to read '.$path);

        return $contents;
    }
}
