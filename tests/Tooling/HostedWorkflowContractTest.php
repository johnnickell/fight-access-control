<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HostedWorkflowContractTest extends TestCase
{
    public function test_that_hosted_ci_resolves_latest_compatible_dependencies_then_delegates_quality(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tests.yml');
        self::assertNotFalse($workflow);

        $resolvePosition = strpos($workflow, 'composer update --no-interaction --no-progress --prefer-dist');
        $qualityPosition = strpos($workflow, './bin/quality');

        self::assertNotFalse($resolvePosition);
        self::assertNotFalse($qualityPosition);
        self::assertLessThan($qualityPosition, $resolvePosition);
        self::assertSame(1, substr_count($workflow, './bin/quality'));
        self::assertStringNotContainsString('./bin/build', $workflow);

        foreach (['phpunit', 'phpstan', 'phpcs', 'rector', 'deptrac', 'coverage'] as $duplicatedGate) {
            self::assertStringNotContainsString('vendor/bin/'.$duplicatedGate, $workflow);
        }
    }
}
