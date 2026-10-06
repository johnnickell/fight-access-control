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
    public function test_fixed_unicode_edges_preserve_case_internal_whitespace_and_normalization_forms(): void
    {
        $id = AgentDestinationId::fromString('00000000-0000-4000-8000-000000000057');
        $destination = new AgentCredentialDestination($id, 7);
        $request = new AgentProvisioningRequest("\0\t\u{00A0}Deploy / é\u{3000}\r\n", $destination);
        self::assertSame(
            '["provision","Deploy \\/ \\u00e9","00000000-0000-4000-8000-000000000057",7]',
            $request->canonicalize()
        );
        self::assertSame('Deploy  A', AgentOperationCanonicalization::name("\u{00A0}Deploy  A\u{3000}"));
        self::assertSame("De\tploy\u{00A0}A", AgentOperationCanonicalization::name(" De\tploy\u{00A0}A "));
        self::assertSame(str_repeat('é', 120), AgentOperationCanonicalization::name(str_repeat('é', 120)));
        $edges = [0, 9, 10, 11, 12, 13, 32, 133, 160, 5760, ...range(8192, 8202), 8232, 8233, 8239, 8287, 12288];
        foreach ($edges as $point) {
            $edge = mb_chr($point, 'UTF-8');
            self::assertSame('X', AgentOperationCanonicalization::name($edge.'X'.$edge));
        }

        self::assertSame("\u{200B}X\u{FEFF}", AgentOperationCanonicalization::name("\u{200B}X\u{FEFF}"));
        self::assertSame("e\u{0301}", AgentOperationCanonicalization::name("e\u{0301}"));
        self::assertNotSame(
            AgentOperationCanonicalization::name('é'),
            AgentOperationCanonicalization::name("e\u{0301}")
        );
        AgentOperationCanonicalization::assertSupported(2);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'ascii empty' => [" \t\n\0"];
        yield 'oversized' => [str_repeat('é', 121)];
        yield 'invalid encoding' => ["\xFF"];
        yield 'unicode empty' => ["\u{00A0}\u{3000}"];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_name_never_falls_back(string $name): void
    {
        try {
            AgentOperationCanonicalization::name($name);
            self::fail('Invalid input must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
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
