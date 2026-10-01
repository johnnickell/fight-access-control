<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class PermissionFeatureReferenceConformance extends TestCase
{
    public function test_all_stored_statuses_block_removal_by_identity_including_references_beyond_a_list_page(): void
    {
        foreach (FeatureStatus::cases() as $status) {
            $environment = $this->environment();
            $permission = $this->permission();
            $environment->storePermission($permission);
            for ($index = 0; $index < 25; ++$index) {
                $environment->storeFeature(Feature::reconstitute(
                    FeatureId::generate(),
                    FeatureName::fromString('flag-'.$index),
                    $permission->getId(),
                    $status,
                    1
                ));
            }

            self::assertFalse($environment->removePermission($permission));
            self::assertSame($permission, $environment->permission($permission));
        }
    }

    public function test_committed_reference_wins_and_unreferenced_permission_can_be_removed(): void
    {
        $environment = $this->environment();
        $permission = $this->permission();
        $other = $this->permission();
        $environment->storePermission($permission);
        $environment->storePermission($other);

        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('registered'), $permission->getId());
        $environment->storeFeature($feature);

        self::assertFalse($environment->removePermission($permission));
        self::assertTrue($environment->removePermission($other));
        self::assertSame($feature, $environment->feature($feature));
        self::assertSame($permission, $environment->permission($permission));
        self::assertNull($environment->permission($other));
    }

    public function test_committed_removal_wins_and_a_later_reference_cannot_rebind_by_name(): void
    {
        $environment = $this->environment();
        $permission = $this->permission();
        $environment->storePermission($permission);
        self::assertTrue($environment->removePermission($permission));
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('later'), $permission->getId());

        try {
            $environment->storeFeature($feature);
            self::fail('A removed identity cannot become a Feature reference.');
        } catch (FeatureReferenceException) {
            self::assertNull($environment->feature($feature));
            self::assertNull($environment->permission($permission));
        }
    }

    abstract protected function environment(): PermissionFeatureReferenceEnvironment;

    private function permission(): Permission
    {
        return Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('TEST_FEATURES'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
