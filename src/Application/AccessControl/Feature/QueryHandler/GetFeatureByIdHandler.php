<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Class GetFeatureByIdHandler
 *
 * Reads one current Feature setting without changing authority.
 */
final readonly class GetFeatureByIdHandler implements QueryHandler
{
    /**
     * Constructs GetFeatureByIdHandler
     */
    public function __construct(private FeatureRepository $features, private PermissionRepository $permissions)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return GetFeatureById::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): FeatureView
    {
        /** @var GetFeatureById $query */
        $query = $queryMessage->payload();
        $feature = $this->features->getById($query->getFeatureId());
        if (!$feature instanceof Feature) {
            throw new FeatureNotFoundException('The Feature does not exist.');
        }

        return FeatureView::fromFeature($feature, $this->permissions->getById($feature->getPermissionId()));
    }
}
