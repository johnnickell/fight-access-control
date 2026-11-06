<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use UnexpectedValueException;

/**
 * Class GetPermissionByIdHandler
 *
 * Retrieves a safe permission-identity view by stable identifier.
 */
final readonly class GetPermissionByIdHandler implements QueryHandler
{
    /**
     * Constructs GetPermissionByIdHandler
     *
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
    public function handle(QueryMessage $queryMessage): PermissionView
    {
        /** @var GetPermissionById $query */
        $query = $queryMessage->payload();
        $permission = $this->permissionRepository->getById($query->getPermissionId());

        if ($permission === null) {
            throw new ResourceNotFound('The Permission is unavailable.');
        }

        if (!$permission->getId()->equals($query->getPermissionId())) {
            throw new UnexpectedValueException('The Permission lookup returned a different identity.');
        }

        return PermissionView::fromPermission($permission);
    }
}
