<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Retrieves safe permission views.
 */
final readonly class ListPermissionsHandler implements QueryHandler
{
    /**
     * Creates the permission-listing query handler.
     */
    public function __construct(private PermissionRepository $permissionRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ListPermissions::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListPermissions $query */
        $query = $queryMessage->payload();

        $permissions = $this->permissionRepository->getAll($query->getPagination());
        $views = $permissions->records()->map(
            fn(Permission $permission): PermissionView => PermissionView::fromPermission($permission),
            PermissionView::class
        );

        return new ResultSet(
            $permissions->page(),
            $permissions->perPage(),
            $permissions->totalRecords(),
            $views
        );
    }
}
