<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent\Operation;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOperationContract::class)]
final class AgentOperationContractTest extends TestCase
{
    public function test_compatible_creation_and_cohort_bound_authority_do_not_grant_or_extend_authorization(): void
    {
        $first = new AgentOperationContract(1, 1, [1], 1, 1, AgentOperationContract::REQUIRED_CAPABILITIES);
        $next = new AgentOperationContract(1, 1, [1], 1, 2, AgentOperationContract::REQUIRED_CAPABILITIES);
        $expiry = new DateTimeImmutable('2026-09-28T12:00:00Z');
        $authority = new AgentDeliveryAuthority('worker:policy:1', $expiry);
        $first->assertSameCohort(new AgentOperationContract(
            1,
            1,
            [1],
            1,
            1,
            AgentOperationContract::REQUIRED_CAPABILITIES
        ));
        self::assertSame(1, $first->getCreationVersion());
        self::assertSame($expiry, $first->bindAuthority($authority)->getExpiresAt());
        self::assertSame($first->bindAuthority($authority)->getEpoch(), $first->bindAuthority($authority)->getEpoch());
        self::assertNotSame(
            $first->bindAuthority($authority)->getEpoch(),
            $next->bindAuthority($authority)->getEpoch()
        );
        self::assertNotSame(
            $first->bindAuthority($authority)->getEpoch(),
            $first->bindAuthority(new AgentDeliveryAuthority('worker:policy:2', $expiry))->getEpoch()
        );
    }

    public function test_individually_compatible_but_different_repository_cohorts_reject(): void
    {
        $first = new AgentOperationContract(1, 1, [1], 1, 1, AgentOperationContract::REQUIRED_CAPABILITIES);
        $next = new AgentOperationContract(1, 1, [1], 1, 2, AgentOperationContract::REQUIRED_CAPABILITIES);
        $this->expectException(AgentOperationRejectedException::class);
        $first->assertSameCohort($next);
    }

    /** @return iterable<string, array{AgentOperationContract}> */
    public static function incompatible(): iterable
    {
        $capabilities = AgentOperationContract::REQUIRED_CAPABILITIES;
        yield 'storage' => [new AgentOperationContract(2, 1, [1], 1, 1, $capabilities)];
        yield 'creation' => [new AgentOperationContract(1, 2, [1], 1, 1, $capabilities)];
        yield 'missing reader' => [new AgentOperationContract(1, 1, [], 1, 1, $capabilities)];
        yield 'unknown retained reader' => [new AgentOperationContract(1, 1, [1, 2], 1, 1, $capabilities)];
        yield 'destination' => [new AgentOperationContract(1, 1, [1], 2, 1, $capabilities)];
        yield 'generation' => [new AgentOperationContract(1, 1, [1], 1, 0, $capabilities)];
        foreach ($capabilities as $missing) {
            yield $missing => [new AgentOperationContract(
                1,
                1,
                [1],
                1,
                1,
                array_values(array_diff($capabilities, [$missing]))
            )];
        }
    }

    #[DataProvider('incompatible')]
    public function test_unknown_or_incomplete_contract_denies_without_fallback(AgentOperationContract $contract): void
    {
        try {
            $contract->getCreationVersion();
            self::fail('An incompatible worker must not select a new-key canonicalizer.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }
}
