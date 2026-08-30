<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AuthenticatedAgentPrincipalException;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Captures an authenticated Agent identity and authoritative direct-Permission snapshot.
 */
final readonly class AuthenticatedAgentPrincipal implements Arrayable, AuthenticatedAuthority
{
    /** @var list<PrincipalPermission> */
    private array $permissions;

    /**
     * Creates the immutable authenticated Agent-principal snapshot.
     *
     * @phpstan-param array<mixed> $permissions
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private int $permissionAssignmentRevision,
        array $permissions
    ) {
        if ($credentialRevision < 0) {
            throw new AuthenticatedAgentPrincipalException('The Agent credential revision cannot be negative.');
        }

        if ($permissionAssignmentRevision < 1) {
            throw new AuthenticatedAgentPrincipalException(
                'The Agent Permission-assignment revision must be positive.'
            );
        }

        $uniquePermissions = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof PrincipalPermission) {
                throw new AuthenticatedAgentPrincipalException(
                    'Authenticated Agent principal Permissions must be PrincipalPermission snapshots.'
                );
            }

            $uniquePermissions[$permission->getPermissionId()->toString()] ??= $permission;
        }

        $this->permissions = array_values($uniquePermissions);
    }

    /**
     * Returns the stable authenticated Agent identity.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the authenticated current credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the authenticated current credential revision.
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns the authoritative direct-Permission assignment revision.
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }

    /**
     * Returns the supported authenticated-principal type.
     */
    public function getType(): AuthenticatedPrincipalType
    {
        return AuthenticatedPrincipalType::AGENT;
    }

    /**
     * Returns the complete ordered direct-Permission snapshots.
     *
     * @return list<PrincipalPermission>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Determines whether the direct-Permission snapshot contains a canonical name.
     */
    public function hasPermission(PermissionName $permissionName): bool
    {
        return array_any(
            $this->permissions,
            static fn(PrincipalPermission $permission): bool => $permission->getName()->equals($permissionName)
        );
    }

    /**
     * Determines whether the direct-Permission snapshot contains a role name.
     *
     * Agents have no Role authority.
     */
    public function hasRole(RoleName $roleName): bool
    {
        return false;
    }

    /**
     * Returns the exact secret-free array representation.
     *
     * @return array{
     *     agent_id: string,
     *     credential_id: string,
     *     credential_revision: int,
     *     permission_assignment_revision: int,
     *     permissions: list<array{permission_id: string, name: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId->toString(),
            'credential_id' => $this->credentialId->toString(),
            'credential_revision' => $this->credentialRevision,
            'permission_assignment_revision' => $this->permissionAssignmentRevision,
            'permissions' => array_map(
                static fn(PrincipalPermission $permission): array => $permission->toArray(),
                $this->permissions
            ),
        ];
    }
}
