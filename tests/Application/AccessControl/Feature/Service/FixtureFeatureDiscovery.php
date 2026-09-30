<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use ReflectionClass;
use Throwable;

/**
 * Models a consumer's already-selected code scope, not a real filesystem or running-code scanner.
 */
final readonly class FixtureFeatureDiscovery implements FeatureReferenceDiscovery
{
    /**
     * @param list<string> $registrations
     * @param Closure(): bool $finishScan
     */
    public function __construct(
        private object $code,
        private array $registrations,
        private Closure $finishScan
    ) {
    }

    public function discover(FeatureReferenceScope $scope): FeatureDiscoveryResult
    {
        try {
            $names = [];
            foreach (new ReflectionClass($this->code)->getMethods() as $method) {
                foreach ($method->getAttributes(FeatureFlag::class) as $attribute) {
                    $names[] = $attribute->newInstance()->getName();
                }
            }

            $references = new FeatureReferences(...$names)->merge(
                FeatureReferences::fromStrings(...$this->registrations)
            );
            if (!($this->finishScan)()) {
                return FeatureDiscoveryResult::unavailable($scope);
            }

            return FeatureDiscoveryResult::complete($scope, $references);
        } catch (Throwable) {
            return FeatureDiscoveryResult::unavailable($scope);
        }
    }
}
