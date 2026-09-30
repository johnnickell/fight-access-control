<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\Service;

use Fight\AccessControl\Application\AccessControl\Feature\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;

/**
 * Interface FeatureReferenceDiscovery
 *
 * Defines consumer-owned scanning and explicit-registration composition for one intended code inventory.
 */
interface FeatureReferenceDiscovery
{
    /**
     * Returns complete or unavailable discovery for the requested code scope
     *
     * Validate every declared and registered name. Complete means every supported reference from the intended
     * candidate or currently running code was included. Instantiate native Attributes to validate their target,
     * multiplicity and arguments. Failed or incomplete discovery must return unavailable or propagate failure,
     * never complete with an empty or partial inventory. Candidate evidence must not be relabeled as current.
     * Consumers own code identity, scanner coverage and freshness; this contract supplies no scanner or cache.
     */
    public function discover(FeatureReferenceScope $scope): FeatureDiscoveryResult;
}
