<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeaturePermissionChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureRemoved;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationProblem;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateFeature::class)]
#[CoversClass(ProvisionFeatures::class)]
#[CoversClass(RemoveFeature::class)]
#[CoversClass(SetFeaturePermission::class)]
#[CoversClass(SetFeatureStatus::class)]
#[CoversClass(FeatureCreated::class)]
#[CoversClass(FeaturePermissionChanged::class)]
#[CoversClass(FeatureRemoved::class)]
#[CoversClass(FeatureStatusChanged::class)]
#[CoversClass(Feature::class)]
#[CoversClass(FeaturePreparationIssue::class)]
#[CoversClass(FeaturePreparationResult::class)]
#[CoversClass(FeatureView::class)]
#[CoversClass(GetFeatureById::class)]
#[CoversClass(ListFeatures::class)]
#[CoversClass(ValidateFeaturePreparation::class)]
final class FeatureTypeContractsTest extends TestCase
{
    /** @param class-string<Payload> $class
     * @param array<string, mixed> $payload
     */
    #[DataProvider('validMessages')]
    public function test_current_messages_round_trip(string $class, array $payload): void
    {
        self::assertSame($payload, $class::fromArray($payload)->toArray());
    }

    /** @return iterable<string, array{class-string<Payload>, array<string, mixed>}> */
    public static function validMessages(): iterable
    {
        $featureId = '018f0000-0000-7000-8000-000000000001';
        $permissionId = '018f0000-0000-7000-8000-000000000002';
        yield 'create' => [CreateFeature::class, [
            'name' => 'checkout', 'permission_id' => $permissionId, 'actor_kind' => null, 'actor_id' => null
        ]];
        yield 'provision' => [ProvisionFeatures::class, ['default_permission_name' => null]];
        yield 'remove' => [RemoveFeature::class, [
            'feature_id' => $featureId, 'expected_revision' => 1, 'actor_kind' => null, 'actor_id' => null
        ]];
        yield 'permission' => [SetFeaturePermission::class, [
            'feature_id' => $featureId, 'permission_id' => $permissionId, 'expected_revision' => 1
        ]];
        yield 'status' => [SetFeatureStatus::class, [
            'feature_id' => $featureId, 'status' => 'preview', 'expected_revision' => 1
        ]];
        yield 'created' => [FeatureCreated::class, [
            'feature_id' => $featureId, 'name' => 'checkout', 'permission_id' => $permissionId
        ]];
        yield 'binding fact' => [FeaturePermissionChanged::class, [
            'feature_id' => $featureId, 'permission_id' => $permissionId, 'revision' => 2
        ]];
        yield 'removed' => [FeatureRemoved::class, ['feature_id' => $featureId, 'name' => 'checkout']];
        yield 'status fact' => [FeatureStatusChanged::class, [
            'feature_id' => $featureId, 'status' => 'on', 'revision' => 2
        ]];
        yield 'get' => [GetFeatureById::class, ['feature_id' => $featureId]];
        yield 'list' => [ListFeatures::class, ['page' => 2, 'per_page' => 7, 'orderings' => ['name' => 'DESC']]];
        yield 'preparation' => [ValidateFeaturePreparation::class, []];
    }

    /** @param class-string<Payload> $class
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidMessages')]
    public function test_messages_reject_missing_and_wrong_types_without_coercion(string $class, array $payload): void
    {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }

    /** @return iterable<string, array{class-string<Payload>, array<string, mixed>}> */
    public static function invalidMessages(): iterable
    {
        foreach (self::validMessages() as $label => [$class, $payload]) {
            foreach ($payload as $key => $original) {
                if (in_array($key, ['actor_kind', 'actor_id'], true)) {
                    continue;
                }

                $missing = $payload;
                unset($missing[$key]);
                yield $label.' missing '.$key => [$class, $missing];
                foreach ([null, true, 1, 1.5, '2', []] as $index => $wrong) {
                    if (get_debug_type($wrong) === get_debug_type($original)) {
                        continue;
                    }

                    if ($class === ProvisionFeatures::class && is_string($wrong)) {
                        continue;
                    }

                    yield $label.' '.$key.' type '.$index => [$class, array_replace($payload, [$key => $wrong])];
                }
            }
        }

        foreach ([['name' => 1], ['name'], [1 => 'ASC']] as $index => $orderings) {
            yield 'ordering '.$index => [ListFeatures::class, [
                'page' => 1, 'per_page' => 10, 'orderings' => $orderings
            ]];
        }

        yield 'preparation fields' => [ValidateFeaturePreparation::class, ['page' => 1]];
    }

    public function test_named_preparation_issues_are_projected_as_a_json_list(): void
    {
        $issue = new FeaturePreparationIssue(
            FeatureName::fromString('checkout'),
            FeaturePreparationProblem::MISSING_FEATURE
        );
        $result = new FeaturePreparationResult(checkout: $issue);
        self::assertSame([$issue], $result->getIssues());
        self::assertSame([
            'prepared' => false,
            'issues'   => [['name' => 'checkout', 'problem' => 'missing_feature']]
        ], $result->toArray());
        self::assertStringContainsString('"issues":[{', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidViewRevisions')]
    public function test_view_rejects_invalid_expected_state_revision(int $revision): void
    {
        $this->expectException(FeatureStateException::class);
        new FeatureView(
            FeatureId::generate(),
            FeatureName::fromString('checkout'),
            FeatureStatus::OFF,
            PermissionId::generate(),
            null,
            $revision
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidViewRevisions(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('transitions')]
    public function test_revision_exhaustion_rejects_before_integer_overflow(string $transition): void
    {
        $feature = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('checkout'),
            PermissionId::generate(),
            FeatureStatus::OFF,
            PHP_INT_MAX
        );
        self::assertSame($feature, $feature->withStatus(FeatureStatus::OFF));
        self::assertSame($feature, $feature->withPermission($feature->getPermissionId()));
        $this->expectException(FeatureStateException::class);
        if ($transition === 'status') {
            $feature->withStatus(FeatureStatus::ON);
        } else {
            $feature->withPermission(PermissionId::generate());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function transitions(): iterable
    {
        yield 'status' => ['status'];
        yield 'permission' => ['permission'];
    }
}
