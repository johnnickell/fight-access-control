<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;

/**
 * Consumer binding for current-code discovery, shared transaction and expected-state Feature removal.
 */
interface FeatureRetirementEnvironment extends FeatureManagementEnvironment
{
    /** @param list<string> $registrations */
    public function discovery(array $registrations, bool $complete = true): FeatureReferenceDiscovery;
}
