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

    public function test_supported_upgrade_requires_agreed_creation_and_retained_reader_obligations(): void
    {
        $capabilities = AgentOperationContract::REQUIRED_CAPABILITIES;
        $upgraded = new AgentOperationContract(1, 2, [1, 2], 1, 2, $capabilities);
        self::assertSame(2, $upgraded->getCreationVersion());
        $upgraded->assertSameCohort(new AgentOperationContract(1, 2, [2, 1], 1, 2, $capabilities));
        foreach (
            [
            new AgentOperationContract(1, 1, [1, 2], 1, 2, $capabilities),
            new AgentOperationContract(1, 1, [1], 1, 2, $capabilities)
            ] as $oldCreator
        ) {
            try {
                $upgraded->assertSameCohort($oldCreator);
                self::fail('Matching generation does not excuse contradictory cohort settings.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $exception->getReason());
            }
        }

        $retained = new AgentOperationContract(1, 1, [1, 2], 1, 3, $capabilities);
        foreach ([[1], [1, 2, 2]] as $readers) {
            $other = new AgentOperationContract(1, 1, $readers, 1, 3, $capabilities);
            if ($readers === [1]) {
                try {
                    $other->assertSameCohort($retained);
                    self::fail('Missing retained reader obligations must reject.');
                } catch (AgentOperationRejectedException $exception) {
                    self::assertSame(AgentOperationFailure::UNAVAILABLE, $exception->getReason());
                }
            } else {
                $retained->assertSameCohort($other);
            }
        }
    }

    /** @return iterable<string, array{AgentOperationContract}> */
    public static function incompatible(): iterable
    {
        $capabilities = AgentOperationContract::REQUIRED_CAPABILITIES;
        yield 'storage' => [new AgentOperationContract(2, 1, [1], 1, 1, $capabilities)];
        yield 'missing creation reader' => [new AgentOperationContract(1, 2, [1], 1, 1, $capabilities)];
        yield 'unknown creation' => [new AgentOperationContract(1, 99, [1], 1, 1, $capabilities)];
        yield 'missing reader' => [new AgentOperationContract(1, 1, [], 1, 1, $capabilities)];
        yield 'unknown retained reader' => [new AgentOperationContract(1, 1, [1, 99], 1, 1, $capabilities)];
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
