<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery;

use Closure;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Throwable;

/**
 * Models a consumer's already-selected code scope, not a real filesystem or running-code scanner.
 */
final readonly class FixtureFeatureDiscovery implements FeatureReferenceDiscovery
{
    /**
     * @param class-string|object $code
     * @param list<string> $registrations
     * @param Closure(): bool $finishScan
     */
    public function __construct(
        private FeatureReferenceScope $codeScope,
        private object|string $code,
        private array $registrations,
        private Closure $finishScan
    ) {
    }

    public function discover(FeatureReferenceScope $scope): FeatureDiscoveryResult
    {
        // A selected candidate inventory cannot be relabeled as current (or the reverse).
        if ($scope !== $this->codeScope) {
            return FeatureDiscoveryResult::unavailable($scope);
        }

        try {
            $names = [];
            $className = $this->code;
            if (is_object($this->code)) {
                $className = $this->code::class;
            }

            foreach (SelectedAction::ancestry($className) as $class) {
                $sites = [$class, ...$class->getProperties(), ...$class->getReflectionConstants()];
                foreach ($class->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() === $class->getName()) {
                        $sites[] = $method;
                        array_push($sites, ...$method->getParameters());
                    }
                }

                foreach ($sites as $site) {
                    foreach ($site->getAttributes(FeatureFlag::class) as $attribute) {
                        $names[] = $attribute->newInstance()->getName();
                    }
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
