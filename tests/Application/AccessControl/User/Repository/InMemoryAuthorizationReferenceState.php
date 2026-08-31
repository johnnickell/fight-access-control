<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

/**
 * Shared persistence state modelling the adapter-owned authorization-reference fences.
 */
final class InMemoryAuthorizationReferenceState
{
    /** @var array<string, true> */
    private array $permissions = [];

    /** @var array<string, Agent> */
    private array $agents = [];

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, User> */
    private array $users = [];

    private bool $referenceFenceHeld = false;

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function addPermission(PermissionId $id): void
    {
        $this->permissions[$id->toString()] = true;
    }

    public function removePermission(PermissionId $id): void
    {
        unset($this->permissions[$id->toString()]);
    }

    /** @param list<PermissionId> $ids */
    public function permissionsAreAuthoritative(array $ids): bool
    {
        return array_all($ids, fn(PermissionId $id): bool => isset($this->permissions[$id->toString()]));
    }

    public function addRole(Role $role): void
    {
        $this->roles[$role->getId()->toString()] = $role;
    }

    public function removeRole(RoleId $id): void
    {
        unset($this->roles[$id->toString()]);
    }

    /** @param list<RoleId> $ids */
    public function rolesAreAuthoritative(array $ids): bool
    {
        return array_all($ids, fn(RoleId $id): bool => isset($this->roles[$id->toString()]));
    }

    public function roleContainsPermission(PermissionId $id): bool
    {
        return array_any($this->roles, static fn(Role $role): bool => $role->hasPermission($id));
    }

    public function retainAgent(Agent $agent): void
    {
        $this->agents[$agent->getId()->toString()] = $agent;
    }

    public function removeAgent(Agent $agent): void
    {
        unset($this->agents[$agent->getId()->toString()]);
    }

    public function agentContainsPermission(PermissionId $id): bool
    {
        return array_any($this->agents, static fn(Agent $agent): bool => $agent->hasPermission($id));
    }

    public function retainUser(User $user): void
    {
        $this->users[$user->getId()->toString()] = $user;
    }

    public function removeUser(User $user): void
    {
        unset($this->users[$user->getId()->toString()]);
    }

    public function hasUserRole(RoleId $id): bool
    {
        return array_any($this->users, static fn(User $user): bool => $user->hasRole($id));
    }

    public function holdThroughCompletion(): void
    {
        $this->referenceFenceHeld = true;
        $this->unitOfWork?->onCompletion(function (): void {
            $this->referenceFenceHeld = false;
        });
    }

    public function isReferenceFenceHeld(): bool
    {
        return $this->referenceFenceHeld;
    }
}
