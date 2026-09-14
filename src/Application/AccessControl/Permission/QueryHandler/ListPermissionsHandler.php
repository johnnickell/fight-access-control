<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Class ListPermissionsHandler
 *
 * Retrieves safe permission views.
 */
final readonly class ListPermissionsHandler implements QueryHandler
{
    /**
     * Constructs ListPermissionsHandler
     *
     * Creates the permission-listing query handler.
     */
    public function __construct(private PermissionRepository $permissionRepository)
    {
    }

    /** @inheritDoc */
    public static function queryRegistration(): string
    {
        return ListPermissions::class;
    }

    /**
     * Returns safe Permission views
     *
     * @return ResultSet<PermissionView>
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListPermissions $query */
        $query = $queryMessage->payload();

        $permissions = $this->permissionRepository->getAll($query->getPagination());
        $views = ArrayList::of(PermissionView::class);
        foreach ($permissions->records() as $permission) {
            $views->add(PermissionView::fromPermission($permission));
        }

        return new ResultSet(
            $permissions->page(),
            $permissions->perPage(),
            $permissions->totalRecords(),
            $views
        );
    }
}
