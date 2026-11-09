<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\Admission;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use LogicException;

/**
 * Class FeatureAdmission
 *
 * Applies current conjunctive restrictions without authorizing an action or refreshing captured grants.
 */
final readonly class FeatureAdmission
{
    /**
     * Constructs FeatureAdmission
     */
    public function __construct(private FeatureAvailability $featureAvailability)
    {
    }

    /**
     * Returns the trusted snapshot after all effective Feature restrictions pass
     */
    public function admit(FeatureReferences $references, ?SecurityContext $context): ?SecurityContext
    {
        if ($references->getNames() === []) {
            return null;
        }

        $principal = $context?->getAuthenticatedAuthority();
        if (
            $principal !== null
            && !$principal instanceof AuthenticatedUserPrincipal
            && !$principal instanceof AuthenticatedAgentPrincipal
        ) {
            throw new LogicException('Feature admission requires a supported authenticated snapshot.');
        }

        foreach ($references->getNames() as $name) {
            try {
                $available = $this->featureAvailability->isAvailable($name, $principal);
            } catch (FeatureUnavailable $featureUnavailable) {
                // A collaborator throwing our outcome is still a fault, not an owned false result.
                throw new LogicException('Feature evaluation failed.', previous: $featureUnavailable);
            }

            if (!$available) {
                throw new FeatureUnavailable('The target is unavailable.');
            }
        }

        return $context;
    }
}
