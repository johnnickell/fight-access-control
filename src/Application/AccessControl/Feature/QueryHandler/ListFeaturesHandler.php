<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\DomainException;
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
    public function __construct(
        private FeatureRepository $featureRepository,
        private PermissionRepository $permissionRepository
    ) {
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
        $page = $this->featureRepository->getAll($query->getPagination());
        $page = ResultSet::strict($page->page(), $page->perPage(), $page->totalRecords(), $page->records());
        if (
            $page->page() !== $query->getPagination()->page()
            || $page->perPage() !== $query->getPagination()->perPage()
        ) {
            throw new DomainException('The Feature page does not match the requested pagination.');
        }

        $views = ArrayList::of(FeatureView::class);
        foreach ($page->records()->toArray() as $feature) {
            $views->add(FeatureView::fromFeature(
                $feature,
                $this->permissionRepository->getById($feature->getPermissionId())
            ));
        }

        return ResultSet::strict($page->page(), $page->perPage(), $page->totalRecords(), $views);
    }
}
