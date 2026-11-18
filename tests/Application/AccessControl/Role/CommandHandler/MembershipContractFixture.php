<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler as RoleHandlers;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler as UserHandlers;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Command as RoleCommands;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command as UserCommands;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;

final readonly class MembershipContractFixture
{
    public InMemoryUnitOfWork $unitOfWork;

    public InMemoryRoleRepositoryState $roleState;

    public InMemoryRoleRepository $roleRepository;

    public InMemoryPermissionRepository $permissionRepository;

    public InMemoryUserRepository $userRepository;

    public InMemoryEventDispatcher $eventDispatcher;

    public Role $role;

    public Permission $permission;

    public User $user;

    public Command $command;

    public function __construct(public string $operation, bool $noOp = false)
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->roleState = new InMemoryRoleRepositoryState();
        $this->roleRepository = new InMemoryRoleRepository($this->unitOfWork, state: $this->roleState);
        $this->permissionRepository = new InMemoryPermissionRepository($this->unitOfWork);
        $this->userRepository = new InMemoryUserRepository($this->unitOfWork);
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $this->permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('READ_CASES'),
            $now
        );
        $this->permissionRepository->add($this->permission);
        $withPermission = ($operation === 'revoke') !== $noOp;
        $this->role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_CASES'),
            $withPermission ? [$this->permission->getId()] : [],
            $now
        );
        $this->roleRepository->add($this->role);
        $withRole = ($operation === 'unassign') !== $noOp;
        $this->user = UserFixture::withRoleAssignments($withRole ? [$this->role->getId()] : [], 4);
        $this->userRepository->add($this->user);
        $actor = UserId::generate();
        $this->command = match ($operation) {
            'create' => new RoleCommands\CreateCustomRole($actor, RoleId::generate(), RoleName::fromString('ROLE_NEW')),
            'rename' => new RoleCommands\RenameCustomRole(
                $actor,
                $this->role->getId(),
                $noOp ? $this->role->getName() : RoleName::fromString('ROLE_NEW'),
                $this->role->getRevision()
            ),
            'remove' => new RoleCommands\RemoveCustomRole($actor, $this->role->getId(), $this->role->getRevision()),
            'grant' => new RoleCommands\GrantPermissionToCustomRole(
                $actor,
                $this->role->getId(),
                $this->permission->getId()
            ),
            'revoke' => new RoleCommands\RevokePermissionFromCustomRole(
                $actor,
                $this->role->getId(),
                $this->permission->getId()
            ),
            'assign' => new UserCommands\AssignRoleToUser($actor, $this->user->getId(), $this->role->getId()),
            'unassign' => new UserCommands\RemoveRoleFromUser($actor, $this->user->getId(), $this->role->getId()),
            default => throw new LogicException('Unsupported membership operation.')
        };
    }

    public function handler(
        ?RoleRepository $roleRepository = null,
        ?PermissionRepository $permissionRepository = null,
        ?UserRepository $userRepository = null,
        ?InMemoryEventDispatcher $eventDispatcher = null,
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?AuditEvidenceRepository $auditEvidenceRepository = null
    ): CommandHandler {
        $roleRepository ??= $this->roleRepository;
        $permissionRepository ??= $this->permissionRepository;
        $userRepository ??= $this->userRepository;
        $eventDispatcher ??= $this->eventDispatcher;
        $unitOfWork ??= $this->unitOfWork;
        $clock = new FixedClock('2030-01-02T00:00:00Z');

        return match ($this->operation) {
            'create' => new RoleHandlers\CreateCustomRoleHandler(
                $roleRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher
            ),
            'rename' => new RoleHandlers\RenameCustomRoleHandler(
                $roleRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher
            ),
            'remove' => new RoleHandlers\RemoveCustomRoleHandler(
                $roleRepository,
                $userRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher
            ),
            'grant' => new RoleHandlers\GrantPermissionToCustomRoleHandler(
                $roleRepository,
                $permissionRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $auditEvidenceRepository
            ),
            'revoke' => new RoleHandlers\RevokePermissionFromCustomRoleHandler(
                $roleRepository,
                $permissionRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $auditEvidenceRepository
            ),
            'assign' => new UserHandlers\AssignRoleToUserHandler(
                $userRepository,
                $roleRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $auditEvidenceRepository
            ),
            'unassign' => new UserHandlers\RemoveRoleFromUserHandler(
                $userRepository,
                $roleRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher,
                $auditEvidenceRepository
            ),
            default => throw new LogicException('Unsupported membership operation.')
        };
    }

    public function bus(?CommandHandler $handler = null): RoutingCommandBus
    {
        $handler ??= $this->handler();
        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        return new RoutingCommandBus($router);
    }
}
