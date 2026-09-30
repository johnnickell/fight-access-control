<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

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
            $payload = ['default_permission_name' => $name];
            $command = ProvisionFeatures::fromArray($payload);
            self::assertSame($name, $command->getDefaultPermissionName());
            self::assertSame($payload, $command->toArray());
            self::assertSame($payload, new ProvisionFeatures($name)->toArray());
        }
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
