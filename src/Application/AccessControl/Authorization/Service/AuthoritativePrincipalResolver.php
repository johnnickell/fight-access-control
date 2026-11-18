<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\PrincipalResolutionException;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalRole;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use UnexpectedValueException;

/**
 * Class AuthoritativePrincipalResolver
 *
 * Resolves request authentication claims against all authoritative principal state.
 *
 * @internal
 */
final readonly class AuthoritativePrincipalResolver
{
    /**
     * Constructs AuthoritativePrincipalResolver
     *
     * Creates an authoritative principal resolver.
     */
    public function __construct(
        private UserRepository $userRepository,
        private RefreshSessionRepository $refreshSessionRepository,
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock
    ) {
    }

    /**
     * Returns a principal only when every authentication and authorization reference remains valid
     *
     * Missing or stale User/session authority is a refusal. Wrong lookup identities, malformed/incomplete Role or
     * Permission snapshots and reserved-identity contradictions remain internal contract faults. Repository and
     * clock faults propagate unchanged; this resolver never turns them into an expected authentication outcome.
     *
     * @throws PrincipalResolutionException When current User or session authority is absent or stale
     */
    public function resolve(AuthenticationContext $authenticationContext): AuthenticatedUserPrincipal
    {
        $user = $this->userRepository->getById($authenticationContext->getUserId());
        $refreshSession = $this->refreshSessionRepository->getById(
            $authenticationContext->getRefreshSessionId()
        );
        if (
            ($user !== null && !$user->getId()->equals($authenticationContext->getUserId()))
            || ($refreshSession !== null
                && !$refreshSession->getId()->equals($authenticationContext->getRefreshSessionId()))
        ) {
            throw new UnexpectedValueException('The authoritative authentication identities are inconsistent.');
        }

        if (
            $user === null
            || $refreshSession === null
            || !$refreshSession->getUserId()->equals($user->getId())
            || $user->getState() !== UserState::ACTIVE
            || $user->getAuthenticationVersion() !== $authenticationContext->getAuthenticationVersion()
            || $refreshSession->getAuthenticationVersion() !== $authenticationContext->getAuthenticationVersion()
            || !$refreshSession->isUsableAt($this->clock->now())
        ) {
            $this->deny();
        }

        $roleIds = $user->getRoleIds();
        $expectedRoleIds = [];
        foreach ($roleIds as $roleId) {
            $expectedRoleIds[$roleId->toString()] = true;
        }

        $roles = $this->validateRoleList($this->roleRepository->getByIds($roleIds));
        $designated = $this->roleRepository->getByName(RoleName::fromString(Role::SUPER_ADMIN_NAME));
        $designated?->assertConsistentWithSuperAdmin($designated);
        $principalRoles = [];
        $roleNames = [];
        $permissionIds = [];
        foreach ($roles as $role) {
            $roleKey = $role->getId()->toString();
            $roleName = $role->getName()->toString();
            if (!isset($expectedRoleIds[$roleKey]) || isset($roleNames[$roleName])) {
                throw new UnexpectedValueException('The authoritative Role identities are inconsistent.');
            }

            unset($expectedRoleIds[$roleKey]);
            $roleNames[$roleName] = true;
            $role->assertConsistentWithSuperAdmin($designated);

            $principalRoles[] = new PrincipalRole($role->getId(), $role->getName());
            foreach ($role->getPermissionIds() as $permissionId) {
                $permissionIds[$permissionId->toString()] = $permissionId;
            }
        }

        if ($expectedRoleIds !== []) {
            throw new UnexpectedValueException('The authoritative Role snapshot is incomplete.');
        }

        $principalPermissions = new ExactPermissionResolver($this->permissionRepository)->resolve(
            array_values($permissionIds)
        );

        return new AuthenticatedUserPrincipal(
            $user->getId(),
            $refreshSession->getId(),
            $authenticationContext->getAuthenticationVersion(),
            $principalRoles,
            $principalPermissions
        );
    }

    /**
     * Validates the erased repository collection contract at its owning boundary
     *
     * @phpstan-param array<array-key, mixed> $roles
     * @return list<Role>
     */
    private function validateRoleList(array $roles): array
    {
        if (!array_is_list($roles)) {
            throw new UnexpectedValueException('The authoritative Role snapshot must be a list.');
        }

        foreach ($roles as $role) {
            if (!$role instanceof Role) {
                throw new UnexpectedValueException('The authoritative Role snapshot contains an invalid member.');
            }
        }

        return $roles;
    }

    /**
     * Rejects invalid authentication authority without disclosing which reference failed
     *
     * @throws PrincipalResolutionException Always
     */
    private function deny(): never
    {
        throw new PrincipalResolutionException('The current principal authority is not valid.');
    }
}
