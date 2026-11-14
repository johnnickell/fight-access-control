<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;

final class ManagedPolicyAuditFixture
{
    public InMemoryUnitOfWork $unit;

    public InMemoryPermissionRepository $permissions;

    public InMemoryRoleRepository $roles;

    public InMemoryAuditEvidenceRepository $audit;

    public InMemoryEventDispatcher $events;

    public ManagedPolicyPlanner $planner;

    public DateTimeImmutable $now;

    public ManagedPolicy $policy;

    /** @var list<Permission> */
    public array $beforePermissions;

    /** @var list<Role> */
    public array $beforeRoles;

    public PermissionId $createdPermissionId;

    public RoleId $createdRoleId;

    public function __construct()
    {
        $this->unit = new InMemoryUnitOfWork();
        $this->permissions = new InMemoryPermissionRepository($this->unit);
        $this->roles = new InMemoryRoleRepository($this->unit);
        $this->audit = new InMemoryAuditEvidenceRepository($this->unit);
        $this->events = new InMemoryEventDispatcher();
        $this->planner = new ManagedPolicyPlanner(
            $this->permissions,
            $this->roles,
            new InMemoryUserRepository($this->unit),
            new InMemoryAgentRepository($this->unit)
        );
        $this->now = new DateTimeImmutable('2030-01-02T03:04:05.123456+00:00');
        $old = $this->now->modify('-1 day');
        $permissions = [];
        foreach (['KEEP', 'OLD', 'REMOVE'] as $name) {
            $permission = Permission::defineManaged(
                PermissionId::generate(),
                PermissionName::fromString($name),
                PermissionTier::ADMIN_SAFE,
                $old
            );
            $this->permissions->add($permission);
            $permissions[] = $permission;
        }

        $this->beforePermissions = $permissions;
        $roles = [];
        foreach (['ROLE_KEEP', 'ROLE_CHANGE', 'ROLE_REMOVE'] as $name) {
            $role = Role::defineManaged(
                RoleId::generate(),
                RoleName::fromString($name),
                [$permissions[0]->getId()],
                $old
            );
            $this->roles->add($role);
            $roles[] = $role;
        }

        $this->beforeRoles = $roles;
        $this->createdPermissionId = PermissionId::generate();
        $this->createdRoleId = RoleId::generate();
        $this->policy = new ManagedPolicy([
            new ManagedPermissionDefinition(
                $permissions[0]->getId(),
                $permissions[0]->getName(),
                PermissionTier::ADMIN_SAFE
            ),
            new ManagedPermissionDefinition(
                $permissions[1]->getId(),
                PermissionName::fromString('CHANGED'),
                PermissionTier::ADMIN_SAFE
            ),
            new ManagedPermissionDefinition(
                $this->createdPermissionId,
                PermissionName::fromString('NEW'),
                PermissionTier::ADMIN_SAFE
            )
        ], [
            new ManagedRoleDefinition($roles[0]->getId(), $roles[0]->getName(), $roles[0]->getPermissionIds()),
            new ManagedRoleDefinition(
                $roles[1]->getId(),
                RoleName::fromString('ROLE_CHANGED'),
                [$this->createdPermissionId]
            ),
            new ManagedRoleDefinition(
                $this->createdRoleId,
                RoleName::fromString('ROLE_NEW'),
                [$this->createdPermissionId]
            )
        ]);
    }

    public function handler(
        ?AuditEvidenceRepository $audit = null,
        ?EventDispatcher $events = null
    ): ReconcileManagedPolicyHandler {
        return new ReconcileManagedPolicyHandler(
            $this->permissions,
            $this->roles,
            $this->planner,
            $this->unit,
            $events ?? $this->events,
            new FixedClock($this->now),
            $audit
        );
    }
}
