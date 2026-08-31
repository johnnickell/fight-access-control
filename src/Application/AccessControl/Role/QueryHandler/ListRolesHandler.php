<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Retrieves safe role views.
 */
final readonly class ListRolesHandler implements QueryHandler
{
    /**
     * Creates the role-listing query handler.
     */
    public function __construct(private RoleRepository $roleRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return ListRoles::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListRoles $query */
        $query = $queryMessage->payload();

        $roles = $this->roleRepository->getAll($query->getPagination());
        $views = $roles->records()->map(
            fn(Role $role): RoleView => RoleView::fromRole($role),
            RoleView::class
        );

        return new ResultSet(
            $roles->page(),
            $roles->perPage(),
            $roles->totalRecords(),
            $views
        );
    }
}
