<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProvisionFeatures::class)]
#[CoversClass(FeatureCreated::class)]
final class FeatureMessagesTest extends TestCase
{
    public function test_command_preserves_nullable_and_unused_raw_configuration(): void
    {
        foreach ([null, '', 'invalid name', 'TEST_FEATURES'] as $name) {
            $payload = ['default_permission_name' => $name, 'actor_kind' => null, 'actor_id' => null];
            $command = ProvisionFeatures::fromArray($payload);
            self::assertSame($name, $command->getDefaultPermissionName());
            self::assertSame($payload, $command->toArray());
            self::assertSame($payload, new ProvisionFeatures($name)->toArray());
        }
    }

    public function test_explicit_actor_kinds_round_trip_without_worker_substitution(): void
    {
        foreach (AuditActorKind::cases() as $kind) {
            $id = '018f0000-0000-7000-8000-000000000001';
            if (in_array($kind, [AuditActorKind::SYSTEM, AuditActorKind::ANONYMOUS], true)) {
                $id = '00000000-0000-0000-0000-000000000000';
            }

            $actor = $kind->idFromString($id);
            $command = new ProvisionFeatures(null, $actor);
            $payload = ['default_permission_name' => null, 'actor_kind' => $kind->value, 'actor_id' => $id];
            self::assertSame($payload, $command->toArray());
            $restored = ProvisionFeatures::fromArray($payload);
            self::assertSame($payload, $restored->toArray());
            self::assertSame($actor::class, $restored->getActorId()::class);
            self::assertTrue($actor->equals($restored->getActorId()));
        }

        self::assertNull(new ProvisionFeatures(null)->getActorId());
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidCommands')]
    public function test_command_rejects_missing_or_wrongly_typed_configuration(array $payload): void
    {
        $this->expectException(DomainException::class);
        ProvisionFeatures::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCommands(): iterable
    {
        yield 'missing' => [[]];
        $valid = ['default_permission_name' => null, 'actor_kind' => null, 'actor_id' => null];
        foreach (['actor_kind', 'actor_id'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing '.$key => [$missing];
        }

        foreach (
            [
            ['user', null], [null, '018f0000-0000-7000-8000-000000000001'],
            ['unknown', '018f0000-0000-7000-8000-000000000001'], ['user', 'invalid'],
            ['system', '018f0000-0000-7000-8000-000000000001'],
            ['anonymous', '018f0000-0000-7000-8000-000000000001'], [true, 1]
            ] as $index => [$kind, $id]
        ) {
            yield 'actor '.$index => [array_replace($valid, ['actor_kind' => $kind, 'actor_id' => $id])];
        }

        foreach ([123, false, []] as $index => $value) {
            yield 'type '.$index => [['default_permission_name' => $value]];
        }
    }

    public function test_creation_fact_round_trips_exactly_without_claiming_readiness(): void
    {
        $id = FeatureId::generate();
        $name = FeatureName::fromString('new-checkout');
        $permissionId = PermissionId::generate();
        $event = new FeatureCreated($id, $name, $permissionId);
        $expected = [
            'feature_id'    => $id->toString(),
            'name'          => 'new-checkout',
            'permission_id' => $permissionId->toString()
        ];

        self::assertSame($id, $event->getFeatureId());
        self::assertSame($name, $event->getName());
        self::assertSame($permissionId, $event->getPermissionId());
        self::assertSame($expected, $event->toArray());
        self::assertSame($expected, FeatureCreated::fromArray($expected)->toArray());
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidEvents')]
    public function test_creation_fact_rejects_missing_or_invalid_fields(array $payload): void
    {
        $this->expectException(DomainException::class);
        FeatureCreated::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEvents(): iterable
    {
        $valid = [
            'feature_id'    => '018f0000-0000-7000-8000-000000000001',
            'name'          => 'checkout',
            'permission_id' => '018f0000-0000-7000-8000-000000000002'
        ];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield $key.' absent' => [$missing];
            foreach ([null, false, 1, []] as $index => $value) {
                yield $key.' type '.$index => [array_replace($valid, [$key => $value])];
            }
        }

        yield 'invalid name' => [array_replace($valid, ['name' => 'Bad_Name'])];
    }
}
