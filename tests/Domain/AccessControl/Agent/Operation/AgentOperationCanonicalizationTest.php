<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationCanonicalization;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOperationCanonicalization::class)]
#[CoversClass(AgentProvisioningRequest::class)]
final class AgentOperationCanonicalizationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function canonicalNames(): iterable
    {
        // Expected JSON bytes are fixed independently of AgentName and json_encode.
        yield 'mixed edges' => ["\0\t\u{00A0}Deploy / é\u{3000}\r\n", 'Deploy \\/ \\u00e9'];
        yield 'internal whitespace and case' => [" De\tploy\u{00A0}A  B ", 'De\\tploy\\u00a0A  B'];
        yield 'internal nul' => [" A\0B ", 'A\\u0000B'];
        yield '120 code points' => ["\u{3000}".str_repeat('é', 120)."\u{00A0}", str_repeat('\\u00e9', 120)];
        yield 'zero width and bom are names' => [" \u{200B}\u{FEFF} ", '\\u200b\\ufeff'];
        yield 'decomposed remains decomposed' => [" e\u{0301} ", 'e\\u0301'];
        yield 'composed remains composed' => [' é ', '\\u00e9'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'ascii empty' => [" \t\n\0"];
        yield 'unicode empty' => ["\u{00A0}\u{3000}"];
        yield '121 code points' => [str_repeat('é', 121)];
        yield 'invalid byte' => ["\xFF"];
        yield 'invalid suffix' => ["Valid\xC3"];
    }

    #[DataProvider('canonicalNames')]
    public function test_current_marker_and_exact_provisioning_bytes_are_unchanged(string $name, string $jsonName): void
    {
        $destination = new AgentCredentialDestination(
            AgentDestinationId::fromString('00000000-0000-4000-8000-000000000057'),
            7
        );
        $request = new AgentProvisioningRequest($name, $destination);
        self::assertSame($name, $request->getName());
        self::assertSame(
            '["provision","'.$jsonName.'","00000000-0000-4000-8000-000000000057",7]',
            $request->canonicalize()
        );
        self::assertSame(2, AgentOperationCanonicalization::VERSION);
        AgentOperationCanonicalization::assertSupported(2);
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_name_never_falls_back_or_exposes_domain_diagnostics(string $name): void
    {
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        try {
            new AgentProvisioningRequest($name, $destination)->canonicalize();
            self::fail('Invalid input must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            self::assertStringNotContainsString('120 characters', $agentOperationRejectedException->getMessage());
        }
    }

    public function test_only_the_current_persisted_marker_is_supported(): void
    {
        foreach ([1, 0, -1, 99] as $version) {
            try {
                AgentOperationCanonicalization::assertSupported($version);
                self::fail('Unsupported persisted markers must reject.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::UNSUPPORTED_VERSION, $exception->getReason());
                self::assertNull($exception->getPrevious());
            }
        }
    }
}
