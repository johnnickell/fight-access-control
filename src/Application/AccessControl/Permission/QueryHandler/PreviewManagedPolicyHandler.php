<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\QueryHandler;

use Fight\AccessControl\Application\AccessControl\Permission\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Preflights managed authorization definitions without side effects.
 */
final readonly class PreviewManagedPolicyHandler implements QueryHandler
{
    /**
     * Creates the managed-policy preview handler.
     */
    public function __construct(
        private PermissionRepository $permissionRepository,
        private RoleRepository $roleRepository,
        private UserRepository $userRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return PreviewManagedPolicy::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ManagedPolicyPlan
    {
        /** @var PreviewManagedPolicy $query */
        $query = $queryMessage->payload();

        return new ManagedPolicyPlanner(
            $this->permissionRepository,
            $this->roleRepository,
            $this->userRepository
        )->plan(
            $query->getPermissions(),
            $query->getRoles(),
            $query->getReferencedPermissionIds()
        );
    }
}
