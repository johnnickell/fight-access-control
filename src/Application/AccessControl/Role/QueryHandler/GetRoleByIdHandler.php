<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Role\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\Role\Query\GetRoleById;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use UnexpectedValueException;

/**
 * Class GetRoleByIdHandler
 *
 * Retrieves a safe role-identity view by stable identifier.
 */
final readonly class GetRoleByIdHandler implements QueryHandler
{
    /**
     * Constructs GetRoleByIdHandler
     *
     * Creates the role-identity query handler.
     */
    public function __construct(private RoleRepository $roleRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return GetRoleById::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): RoleView
    {
        /** @var GetRoleById $query */
        $query = $queryMessage->payload();
        $role = $this->roleRepository->getById($query->getRoleId());

        if ($role === null) {
            throw new ResourceNotFound('The Role is unavailable.');
        }

        if (!$role->getId()->equals($query->getRoleId())) {
            throw new UnexpectedValueException('The Role lookup returned a different identity.');
        }

        return RoleView::fromRole($role);
    }
}
