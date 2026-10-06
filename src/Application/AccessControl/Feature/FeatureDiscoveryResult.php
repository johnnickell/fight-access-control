<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;

/**
 * Class FeatureDiscoveryResult
 *
 * Carries a consumer's scoped completeness assertion or unavailable discovery.
 */
final readonly class FeatureDiscoveryResult
{
    /**
     * Constructs FeatureDiscoveryResult
     */
    private function __construct(
        private FeatureReferenceScope $scope,
        private ?FeatureReferences $references
    ) {
    }

    /**
     * Creates a result asserting complete discovery for the intended code scope
     *
     * Consumers must finish all scanning and explicit-registration composition before asserting completeness.
     * A complete empty inventory is valid; the package cannot certify a consumer's scanner or code identity.
     */
    public static function complete(FeatureReferenceScope $scope, FeatureReferences $references): self
    {
        return new self($scope, $references);
    }

    /**
     * Creates an unavailable result for failed or incomplete discovery
     *
     * Partial references and arbitrary scanner diagnostics cannot escape through this result.
     */
    public static function unavailable(FeatureReferenceScope $scope): self
    {
        return new self($scope, null);
    }

    /**
     * Returns complete references only for the required code scope
     *
     * Callers must request CANDIDATE for preparation and CURRENT for reference-safe retirement.
     */
    public function getReferences(FeatureReferenceScope $requiredScope): FeatureReferences
    {
        if ($this->scope !== $requiredScope) {
            throw new FeatureDiscoveryException('Feature discovery does not describe the required code scope.');
        }

        if ($this->references === null) {
            throw new FeatureDiscoveryException('Complete Feature discovery is unavailable.');
        }

        return $this->references;
    }
}
