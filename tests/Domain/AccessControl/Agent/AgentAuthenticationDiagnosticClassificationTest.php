<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentAuthenticationDiagnosticClassification::class)]
final class AgentAuthenticationDiagnosticClassificationTest extends TestCase
{
    /** @return iterable<string, array{AgentAuthenticationDiagnosticClassification, bool, string}> */
    public static function classifications(): iterable
    {
        yield 'authentication rejected' => [
            AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
            true,
            'authentication_rejected'
        ];
        yield 'authority no longer current' => [
            AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT,
            true,
            'agent_authority_not_current'
        ];
        yield 'invalid snapshot is a fault' => [
            AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID,
            false,
            'permission_snapshot_invalid'
        ];
        yield 'resolution failure is a fault' => [
            AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED,
            false,
            'resolution_failed'
        ];
    }

    #[DataProvider('classifications')]
    public function test_refusal_classification_preserves_serialized_identity(
        AgentAuthenticationDiagnosticClassification $classification,
        bool $refusal,
        string $value
    ): void {
        self::assertSame($refusal, $classification->isAuthenticationRefusal());
        self::assertSame($value, $classification->value);
    }
}
