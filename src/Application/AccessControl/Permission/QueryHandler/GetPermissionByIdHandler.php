<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Retrieves a safe permission-identity view by stable identifier.
 */
final readonly class GetPermissionByIdHandler implements QueryHandler
{
    /**
     * Creates the permission-identity query handler.
     */
    public function __construct(private PermissionRepository $permissionRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return GetPermissionById::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ?PermissionView
    {
        /** @var GetPermissionById $query */
        $query = $queryMessage->payload();
        $permission = $this->permissionRepository->getById($query->getPermissionId());

        if (!$permission instanceof Permission) {
            return null;
        }

        return PermissionView::fromPermission($permission);
    }
}
