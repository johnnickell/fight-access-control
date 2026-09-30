<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\RequiresFeature;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Service\FixtureFeatureDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(FeatureDiscoveryResult::class)]
#[CoversClass(FeatureReferences::class)]
#[CoversClass(RequiresFeature::class)]
#[CoversClass(FeatureName::class)]
#[CoversClass(FeatureNameException::class)]
#[CoversClass(FeatureDiscoveryException::class)]
final class FeatureDiscoveryResultTest extends TestCase
{
    /** @return iterable<string, array{FeatureReferenceScope}> */
    public static function scopes(): iterable
    {
        yield 'candidate' => [FeatureReferenceScope::CANDIDATE];
        yield 'current' => [FeatureReferenceScope::CURRENT];
    }

    /** @return iterable<string, array{FeatureReferenceScope, bool, bool}> */
    public static function unavailableScans(): iterable
    {
        foreach (FeatureReferenceScope::cases() as $scope) {
            foreach ([false, true] as $partial) {
                foreach ([false, true] as $throws) {
                    yield $scope->value.'-'.(int) $partial.'-'.(int) $throws => [$scope, $partial, $throws];
                }
            }
        }
    }

    #[DataProvider('scopes')]
    public function test_complete_empty_discovery_can_supply_an_empty_inventory(FeatureReferenceScope $scope): void
    {
        $discovery = new FixtureFeatureDiscovery(new stdClass(), [], static fn(): bool => true);

        self::assertSame([], $discovery->discover($scope)->getReferences($scope)->getNames());
    }

    #[DataProvider('scopes')]
    public function test_discovery_combines_native_declarations_and_registration(FeatureReferenceScope $scope): void
    {
        $code = new class {
            #[RequiresFeature('dashboard')]
            public function dashboard(): void
            {
            }

            #[RequiresFeature('dashboard')]
            public function dashboardExport(): void
            {
            }
        };
        $discovery = new FixtureFeatureDiscovery(
            $code,
            ['agent-tools-v2', 'dashboard'],
            static fn(): bool => true
        );
        $references = $discovery->discover($scope)->getReferences($scope);

        self::assertSame(['dashboard', 'agent-tools-v2'], array_map(
            static fn(FeatureName $name): string => $name->toString(),
            $references->getNames()
        ));
    }

    #[DataProvider('unavailableScans')]
    public function test_failed_or_incomplete_discovery_never_exposes_empty_or_partial_names(
        FeatureReferenceScope $scope,
        bool $partial,
        bool $throws
    ): void {
        $registrations = [];
        if ($partial) {
            $registrations = ['dashboard'];
        }

        $finishScan = static function () use ($throws): bool {
            if ($throws) {
                throw new RuntimeException('Private scanner diagnostic');
            }

            return false;
        };
        $discovery = new FixtureFeatureDiscovery(new stdClass(), $registrations, $finishScan);
        $result = $discovery->discover($scope);
        $this->expectException(FeatureDiscoveryException::class);
        $this->expectExceptionMessage('Complete Feature discovery is unavailable.');

        $result->getReferences($scope);
    }

    #[DataProvider('scopes')]
    public function test_complete_inventories_cannot_be_read_as_the_other_code_scope(FeatureReferenceScope $scope): void
    {
        $other = FeatureReferenceScope::CURRENT;
        if ($scope === FeatureReferenceScope::CURRENT) {
            $other = FeatureReferenceScope::CANDIDATE;
        }

        $references = FeatureReferences::fromStrings('dashboard');
        $result = FeatureDiscoveryResult::complete($scope, $references);
        self::assertSame($references, $result->getReferences($scope));
        $this->expectException(FeatureDiscoveryException::class);
        $this->expectExceptionMessage('Feature discovery does not describe the required code scope.');

        $result->getReferences($other);
    }

    public function test_unavailable_candidate_discovery_cannot_be_used_for_current_code(): void
    {
        $result = FeatureDiscoveryResult::unavailable(FeatureReferenceScope::CANDIDATE);
        $this->expectException(FeatureDiscoveryException::class);

        $result->getReferences(FeatureReferenceScope::CURRENT);
    }

    public function test_invalid_explicit_registration_rejects_discovery_instead_of_dropping_the_name(): void
    {
        $discovery = new FixtureFeatureDiscovery(
            new stdClass(),
            ['dashboard', 'Dashboard'],
            static fn(): bool => true
        );
        $this->expectException(FeatureDiscoveryException::class);

        $discovery->discover(FeatureReferenceScope::CANDIDATE)->getReferences(FeatureReferenceScope::CANDIDATE);
    }

    public function test_malformed_native_metadata_cannot_be_reported_as_complete_discovery(): void
    {
        $code = require __DIR__.'/Fixture/InvalidFeatureTargets.php.fixture';
        $discovery = new FixtureFeatureDiscovery($code, ['dashboard'], static fn(): bool => true);
        $this->expectException(FeatureDiscoveryException::class);

        $discovery->discover(FeatureReferenceScope::CURRENT)->getReferences(FeatureReferenceScope::CURRENT);
    }
}
