<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Class ListFeaturesHandler
 *
 * Returns an authoritative page of safe Feature settings.
 */
final readonly class ListFeaturesHandler implements QueryHandler
{
    /**
     * Constructs ListFeaturesHandler
     */
    public function __construct(private FeatureRepository $features, private PermissionRepository $permissions)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ListFeatures::class;
    }

    /**
     * Returns a page of safe results with the requested pagination
     *
     * @return ResultSet<FeatureView>
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListFeatures $query */
        $query = $queryMessage->payload();
        $page = $this->features->getAll($query->getPagination());
        $views = ArrayList::of(FeatureView::class);
        foreach ($page->records() as $feature) {
            $views->add(FeatureView::fromFeature(
                $feature,
                $this->permissions->getById($feature->getPermissionId())
            ));
        }

        return new ResultSet($page->page(), $page->perPage(), $page->totalRecords(), $views);
    }
}
