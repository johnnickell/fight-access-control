<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetFeatureStatus::class)]
#[CoversClass(SetFeaturePermission::class)]
final class FeatureSettingProvenanceTest extends TestCase
{
    public function test_all_direct_actor_kinds_and_absence_round_trip_without_fallback(): void
    {
        $actors = [null, UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate()];
        foreach ($actors as $actor) {
            foreach (
                [
                new SetFeatureStatus(FeatureId::generate(), FeatureStatus::ON, 3, $actor),
                new SetFeaturePermission(FeatureId::generate(), PermissionId::generate(), 3, $actor)
                ] as $command
            ) {
                self::assertSame($actor, $command->getActorId());
                $payload = $command->toArray();
                self::assertSame($actor === null ? null : AuditActorKind::forId($actor)->value, $payload['actor_kind']);
                self::assertSame($actor?->toString(), $payload['actor_id']);
                $restored = $command::fromArray($payload);
                self::assertSame($payload, $restored->toArray());
                $restoredActor = $restored->getActorId();
                self::assertSame(
                    $actor === null ? null : $actor::class,
                    $restoredActor === null ? null : $restoredActor::class
                );
            }
        }

        foreach ([SetFeatureStatus::class, SetFeaturePermission::class] as $class) {
            $command = $class::fromArray([
                'feature_id'        => FeatureId::generate()->toString(),
                'status'            => 'on',
                'permission_id'     => PermissionId::generate()->toString(),
                'expected_revision' => 1
            ]);
            self::assertNull($command->getActorId());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidActors(): iterable
    {
        yield 'kind without identity' => [['actor_kind' => 'user']];
        yield 'identity without kind' => [['actor_id' => UserId::generate()->toString()]];
        yield 'null identity' => [['actor_kind' => 'agent', 'actor_id' => null]];
        yield 'unknown kind' => [['actor_kind' => 'worker', 'actor_id' => UserId::generate()->toString()]];
        yield 'numeric kind' => [['actor_kind' => 1, 'actor_id' => UserId::generate()->toString()]];
        yield 'array identity' => [['actor_kind' => 'user', 'actor_id' => []]];
        yield 'non-nil system' => [['actor_kind' => 'system', 'actor_id' => UserId::generate()->toString()]];
        yield 'non-nil anonymous' => [['actor_kind' => 'anonymous', 'actor_id' => UserId::generate()->toString()]];
    }

    /** @param array<string, mixed> $actor */
    #[DataProvider('invalidActors')]
    public function test_partial_or_malformed_provenance_is_not_coerced(array $actor): void
    {
        $base = [
            'feature_id'        => FeatureId::generate()->toString(),
            'status'            => 'on',
            'permission_id'     => PermissionId::generate()->toString(),
            'expected_revision' => 1
        ];
        foreach ([SetFeatureStatus::class, SetFeaturePermission::class] as $class) {
            try {
                $class::fromArray($base + $actor);
                self::fail('Malformed provenance must reject.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
