<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
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

    /** @inheritDoc */
    public static function queryRegistration(): string
    {
        return ListRoles::class;
    }

    /**
     * @inheritDoc
     *
     * @return ResultSet<RoleView>
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListRoles $query */
        $query = $queryMessage->payload();

        $roles = $this->roleRepository->getAll($query->getPagination());
        $views = ArrayList::of(RoleView::class);
        foreach ($roles->records() as $role) {
            $views->add(RoleView::fromRole($role));
        }

        return new ResultSet(
            $roles->page(),
            $roles->perPage(),
            $roles->totalRecords(),
            $views
        );
    }
}
