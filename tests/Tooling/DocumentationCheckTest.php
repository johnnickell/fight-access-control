<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class DocumentationCheckTest extends TestCase
{
    public function test_that_documentation_check_rejects_a_missing_local_link(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = $root.'/broken-link-fixture.md';
        file_put_contents($fixture, "[missing](not-here.md)\n");

        try {
            $process = new Process(['bash', 'bin/documentation-check'], $root);
            $process->run();
        } finally {
            unlink($fixture);
        }

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('links to missing local target not-here.md', $process->getErrorOutput());
    }
}
