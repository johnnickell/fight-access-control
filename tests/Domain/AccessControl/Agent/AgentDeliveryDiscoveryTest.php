<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListDueAgentDeliveries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentDeliverySchedule::class)]
#[CoversClass(ListDueAgentDeliveries::class)]
final class AgentDeliveryDiscoveryTest extends TestCase
{
    public function test_default_and_override_schedules_bound_work_and_polling(): void
    {
        $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
        $defaults = new AgentDeliverySchedule();
        self::assertSame(50, $defaults->getBatchSize());
        self::assertEquals($now->modify('+30 seconds'), $defaults->nextRunAt($now));
        foreach ([[1, 1], [100, 3600]] as [$batch, $poll]) {
            $schedule = new AgentDeliverySchedule($batch, $poll);
            self::assertSame($batch, $schedule->getBatchSize());
            self::assertEquals($now->modify('+'.$poll.' seconds'), $schedule->nextRunAt($now));
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidSchedules(): iterable
    {
        yield 'zero batch' => [0, 30];
        yield 'excessive batch' => [101, 30];
        yield 'zero poll' => [50, 0];
        yield 'excessive poll' => [50, 3601];
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_overrides_cannot_disable_bounds(int $batch, int $poll): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentDeliverySchedule($batch, $poll);
    }

    public function test_query_roundtrip_preserves_original_scope_destination_and_bounded_limit(): void
    {
        $scope = new AgentOperationScope('consumer', 'user', 'owner');
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $query = new ListDueAgentDeliveries($scope, $destination);
        self::assertSame($scope, $query->getScope());
        self::assertSame($destination, $query->getDestination());
        self::assertSame(50, $query->getLimit());
        self::assertEquals($query, ListDueAgentDeliveries::fromArray($query->toArray()));
        $custom = new ListDueAgentDeliveries($scope, $destination, 7);
        self::assertSame(7, ListDueAgentDeliveries::fromArray($custom->toArray())->getLimit());
        foreach (array_keys($query->toArray()) as $field) {
            $data = $query->toArray();
            unset($data[$field]);
            try {
                ListDueAgentDeliveries::fromArray($data);
                self::fail('Missing required field '.$field);
            } catch (AgentOperationRejectedException $exception) {
                self::assertNull($exception->getPrevious());
            }
        }

        $invalidFields = ['limit' => '50', 'namespace' => [], 'caller_type' => null, 'caller_id' => '/unsafe/path'];
        foreach ($invalidFields as $key => $value) {
            $data = $query->toArray();
            $data[$key] = $value;
            try {
                ListDueAgentDeliveries::fromArray($data);
                self::fail('Malformed query must reject.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertStringNotContainsString('/unsafe/path', $exception->getMessage());
            }
        }

        $this->expectException(AgentOperationRejectedException::class);
        new ListDueAgentDeliveries($scope, $destination, 0);
    }
}
