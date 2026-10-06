<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Feature::class)]
#[CoversClass(FeatureId::class)]
#[CoversClass(FeatureStatus::class)]
final class FeatureTest extends TestCase
{
    public function test_new_features_start_off_with_stable_identity_name_and_required_binding(): void
    {
        $id = FeatureId::generate();
        $name = FeatureName::fromString('new-checkout');
        $permissionId = PermissionId::generate();
        $feature = Feature::define($id, $name, $permissionId);

        self::assertSame($id, $feature->getId());
        self::assertTrue($id->equals(FeatureId::fromString($id->toString())));
        self::assertSame($name, $feature->getName());
        self::assertSame($permissionId, $feature->getPermissionId());
        self::assertSame(FeatureStatus::OFF, $feature->getStatus());
        self::assertSame(1, $feature->getRevision());
    }

    public function test_hydration_preserves_all_persisted_choices_and_revision(): void
    {
        $id = FeatureId::generate();
        $name = FeatureName::fromString('dashboard');
        $permissionId = PermissionId::generate();
        foreach (['off', 'preview', 'on'] as $serialized) {
            $feature = Feature::reconstitute($id, $name, $permissionId, FeatureStatus::from($serialized), 17);

            self::assertSame($id, $feature->getId());
            self::assertSame($name, $feature->getName());
            self::assertSame($permissionId, $feature->getPermissionId());
            self::assertSame($serialized, $feature->getStatus()->value);
            self::assertSame(17, $feature->getRevision());
        }
    }

    #[DataProvider('invalidRevisions')]
    public function test_invalid_hydration_revision_rejects(int $revision): void
    {
        $this->expectException(FeatureStateException::class);
        Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('dashboard'),
            PermissionId::generate(),
            FeatureStatus::OFF,
            $revision
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidRevisions(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function test_factories_preserve_entity_extensibility(): void
    {
        $feature = ExtensibleFeature::define(
            FeatureId::generate(),
            FeatureName::fromString('dashboard'),
            PermissionId::generate()
        );
        self::assertInstanceOf(ExtensibleFeature::class, $feature);
        self::assertInstanceOf(ExtensibleFeature::class, ExtensibleFeature::reconstitute(
            $feature->getId(),
            $feature->getName(),
            $feature->getPermissionId(),
            FeatureStatus::ON,
            2
        ));
    }
}
