<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReconcileManagedPolicy::class)]
final class ReconcileManagedPolicyProvenanceTest extends TestCase
{
    public function test_optional_origin_round_trips_each_distinct_actor_kind(): void
    {
        $actors = [null, UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate()];
        foreach ($actors as $actor) {
            $command = new ReconcileManagedPolicy(new ManagedPolicy([], []), $actor);
            $restored = ReconcileManagedPolicy::fromArray($command->toArray());
            self::assertSame($command->toArray(), $restored->toArray());
            self::assertEquals($actor, $restored->getActorId());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedProvenance(): iterable
    {
        yield 'missing identity' => [['actor_kind' => 'system']];
        yield 'missing kind' => [['actor_id' => SystemId::generate()->toString()]];
        yield 'wrong kind type' => [['actor_kind' => 1, 'actor_id' => SystemId::generate()->toString()]];
        yield 'wrong identity type' => [['actor_kind' => 'user', 'actor_id' => 1]];
        yield 'unknown kind' => [['actor_kind' => 'worker', 'actor_id' => SystemId::generate()->toString()]];
        yield 'non nil system' => [['actor_kind' => 'system', 'actor_id' => UserId::generate()->toString()]];
    }

    #[DataProvider('malformedProvenance')]
    public function test_decoded_provenance_is_explicit_and_never_inferred(array $actor): void
    {
        $this->expectException(DomainException::class);
        ReconcileManagedPolicy::fromArray([...new ManagedPolicy([], [])->toArray(), ...$actor]);
    }
}
