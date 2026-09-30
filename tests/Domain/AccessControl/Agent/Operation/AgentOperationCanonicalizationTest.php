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
    public function test_frozen_v1_bytes_and_v2_edge_whitespace_are_distinct_without_case_or_internal_folding(): void
    {
        $id = AgentDestinationId::fromString('00000000-0000-4000-8000-000000000057');
        $destination = new AgentCredentialDestination($id, 7);
        $request = new AgentProvisioningRequest("\0\t Deploy / é \r\n", $destination);
        self::assertSame(
            '["provision","Deploy \\/ \\u00e9","00000000-0000-4000-8000-000000000057",7]',
            $request->canonicalize(1)
        );
        self::assertSame($request->canonicalize(1), $request->canonicalize(2));
        $unicode = new AgentProvisioningRequest("\u{00A0}Deploy  A\u{3000}", $destination);
        self::assertNotSame($unicode->canonicalize(1), $unicode->canonicalize(2));
        self::assertSame("\u{00A0}Deploy  A\u{3000}", AgentOperationCanonicalization::name($unicode->getName(), 1));
        self::assertSame('Deploy  A', AgentOperationCanonicalization::name($unicode->getName(), 2));
        self::assertSame('Deploy  A', AgentOperationCanonicalization::name('Deploy  A', 1));
        self::assertSame(str_repeat('é', 120), AgentOperationCanonicalization::name(str_repeat('é', 120), 2));
        foreach ([0, 9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8202, 8232, 8233, 8239, 8287, 12288] as $point) {
            $edge = mb_chr($point, 'UTF-8');
            self::assertSame('X', AgentOperationCanonicalization::name($edge.'X'.$edge, 2));
        }

        self::assertSame("\u{200B}X\u{FEFF}", AgentOperationCanonicalization::name("\u{200B}X\u{FEFF}", 2));
    }

    /** @return iterable<string, array{string, int, AgentOperationFailure}> */
    public static function invalid(): iterable
    {
        yield 'unknown' => ['Name', 99, AgentOperationFailure::UNSUPPORTED_VERSION];
        yield 'negative' => ['Name', -1, AgentOperationFailure::UNSUPPORTED_VERSION];
        foreach ([1, 2] as $version) {
            yield $version.' empty' => ['', $version, AgentOperationFailure::INVALID_REQUEST];
            yield $version.' ascii empty' => [" \t\n\0", $version, AgentOperationFailure::INVALID_REQUEST];
            yield $version.' oversized' => [str_repeat('é', 121), $version, AgentOperationFailure::INVALID_REQUEST];
            yield $version.' invalid encoding' => ["\xFF", $version, AgentOperationFailure::INVALID_REQUEST];
        }

        yield 'unicode empty' => ["\u{00A0}\u{3000}", 2, AgentOperationFailure::INVALID_REQUEST];
    }

    #[DataProvider('invalid')]
    public function test_invalid_or_unknown_input_never_falls_back(
        string $name,
        int $version,
        AgentOperationFailure $reason
    ): void {
        try {
            AgentOperationCanonicalization::name($name, $version);
            self::fail('Invalid input must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }
}
