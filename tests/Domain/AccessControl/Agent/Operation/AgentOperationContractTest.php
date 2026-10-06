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
    /** @return iterable<string, array{AgentOperationContract}> */
    public static function incompatible(): iterable
    {
        $capabilities = AgentOperationContract::REQUIRED_CAPABILITIES;
        yield 'storage' => [new AgentOperationContract(2, 2, 1, 1, $capabilities, 1)];
        yield 'obsolete canonical marker' => [new AgentOperationContract(1, 1, 1, 1, $capabilities, 1)];
        yield 'unknown canonical marker' => [new AgentOperationContract(1, 99, 1, 1, $capabilities, 1)];
        yield 'destination' => [new AgentOperationContract(1, 2, 2, 1, $capabilities, 1)];
        yield 'generation' => [new AgentOperationContract(1, 2, 1, 0, $capabilities, 0)];
        yield 'missing reconciliation' => [new AgentOperationContract(1, 2, 1, 1, $capabilities, null)];
        yield 'restored cohort' => [new AgentOperationContract(1, 2, 1, 1, $capabilities, 2)];
        yield 'stale reconciliation' => [new AgentOperationContract(1, 2, 1, 2, $capabilities, 1)];
        yield 'invalid reconciliation' => [new AgentOperationContract(1, 2, 1, 1, $capabilities, 0)];
        foreach ($capabilities as $missing) {
            yield $missing => [new AgentOperationContract(
                1,
                2,
                1,
                1,
                array_values(array_diff($capabilities, [$missing])),
                1
            )];
        }
    }

    public function test_compatible_cohort_bound_authority_does_not_grant_or_extend_authorization(): void
    {
        $capabilities = AgentOperationContract::REQUIRED_CAPABILITIES;
        $first = new AgentOperationContract(1, 2, 1, 1, $capabilities, 1);
        $next = new AgentOperationContract(1, 2, 1, 2, $capabilities, 2);
        $expiry = new DateTimeImmutable('2026-09-28T12:00:00Z');
        $authority = new AgentDeliveryAuthority('worker:policy:1', $expiry);
        $first->assertSameCohort(new AgentOperationContract(1, 2, 1, 1, $capabilities, 1));
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
        $capabilities = AgentOperationContract::REQUIRED_CAPABILITIES;
        $first = new AgentOperationContract(1, 2, 1, 1, $capabilities, 1);
        $next = new AgentOperationContract(1, 2, 1, 2, $capabilities, 2);
        $this->expectException(AgentOperationRejectedException::class);
        $first->assertSameCohort($next);
    }

    #[DataProvider('incompatible')]
    public function test_unknown_or_incomplete_contract_denies_without_fallback(AgentOperationContract $contract): void
    {
        foreach (['compatibility', 'participants', 'authority'] as $path) {
            try {
                match ($path) {
                    'compatibility' => $contract->assertCompatible(),
                    'participants' => new AgentOperationContract(
                        1,
                        2,
                        1,
                        1,
                        AgentOperationContract::REQUIRED_CAPABILITIES,
                        1
                    )->assertSameCohort($contract),
                    'authority' => $contract->bindAuthority(new AgentDeliveryAuthority(
                        'worker:policy:1',
                        new DateTimeImmutable('2026-09-28T12:00:00Z')
                    ))
                };
                self::fail('An incompatible or unreconciled worker must not admit work.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
                self::assertNull($agentOperationRejectedException->getPrevious());
            }
        }
    }
}
