<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\QueryHandler;

use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationProblem;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Class ValidateFeaturePreparationHandler
 *
 * Reads every candidate reference without mutating configuration or deciding availability.
 */
final readonly class ValidateFeaturePreparationHandler implements QueryHandler
{
    /**
     * Constructs ValidateFeaturePreparationHandler
     */
    public function __construct(
        private FeatureReferenceDiscovery $discovery,
        private FeatureRepository $featureRepository,
        private PermissionRepository $permissionRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ValidateFeaturePreparation::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): FeaturePreparationResult
    {
        $query = $queryMessage->payload();
        if (!$query instanceof ValidateFeaturePreparation) {
            throw new DomainException('Unexpected Feature preparation query.');
        }

        $references = $this->discovery->discover(FeatureReferenceScope::CANDIDATE)
            ->getReferences(FeatureReferenceScope::CANDIDATE);
        $issues = [];

        foreach ($references->getNames() as $name) {
            try {
                $feature = $this->featureRepository->getByName($name);
                if (!$feature instanceof Feature) {
                    $issues[] = new FeaturePreparationIssue($name, FeaturePreparationProblem::MISSING_FEATURE);
                    continue;
                }

                if (!$feature->getName()->equals($name)) {
                    $issues[] = new FeaturePreparationIssue($name, FeaturePreparationProblem::INVALID_DEFINITION);
                    continue;
                }

                $permission = $this->permissionRepository->getById($feature->getPermissionId());
                if (!$permission instanceof Permission) {
                    $issues[] = new FeaturePreparationIssue($name, FeaturePreparationProblem::BROKEN_BINDING);
                    continue;
                }

                if (!$permission->getId()->equals($feature->getPermissionId())) {
                    $issues[] = new FeaturePreparationIssue($name, FeaturePreparationProblem::INVALID_DEFINITION);
                }
            } catch (FeatureStateException) {
                // Adapters signal malformed persisted definitions explicitly; outages propagate unchanged.
                $issues[] = new FeaturePreparationIssue($name, FeaturePreparationProblem::INVALID_DEFINITION);
            }
        }

        return new FeaturePreparationResult(...$issues);
    }
}
