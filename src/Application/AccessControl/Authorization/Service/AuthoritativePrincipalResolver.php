<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\PrincipalResolutionException;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalRole;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Collection\HashSet;

/**
 * Resolves request authentication claims against all authoritative principal state.
 */
final readonly class AuthoritativePrincipalResolver
{
    /**
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
     * Returns a principal only when every authentication and authorization reference remains valid.
     *
     * @throws PrincipalResolutionException When current principal authority is not valid
     */
    public function resolve(AuthenticationContext $authenticationContext): AuthenticatedPrincipal
    {
        $user = $this->userRepository->getById($authenticationContext->getUserId());
        $refreshSession = $this->refreshSessionRepository->getById(
            $authenticationContext->getRefreshSessionId()
        );
        if (
            !$user instanceof User
            || !$refreshSession instanceof RefreshSession
            || !$user->getId()->equals($authenticationContext->getUserId())
            || !$refreshSession->getId()->equals($authenticationContext->getRefreshSessionId())
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

        $roles = $this->roleRepository->getByIds($roleIds);
        $principalRoles = [];
        $permissionIds = HashSet::of(PermissionId::class);
        foreach ($roles as $role) {
            $roleKey = $role->getId()->toString();
            if (!isset($expectedRoleIds[$roleKey])) {
                $this->deny();
            }

            unset($expectedRoleIds[$roleKey]);
            $principalRoles[] = new PrincipalRole($role->getId(), $role->getName());
            foreach ($role->getPermissionIds() as $permissionId) {
                $permissionIds->add($permissionId);
            }
        }

        if ($expectedRoleIds !== []) {
            $this->deny();
        }

        $expectedPermissionIds = [];
        foreach ($permissionIds as $permissionId) {
            $expectedPermissionIds[$permissionId->toString()] = true;
        }

        $permissions = $this->permissionRepository->getByIds($permissionIds->toArray());
        $principalPermissions = [];
        foreach ($permissions as $permission) {
            $permissionKey = $permission->getId()->toString();
            if (!isset($expectedPermissionIds[$permissionKey])) {
                $this->deny();
            }

            unset($expectedPermissionIds[$permissionKey]);
            $principalPermissions[] = new PrincipalPermission($permission->getId(), $permission->getName());
        }

        if ($expectedPermissionIds !== []) {
            $this->deny();
        }

        return new AuthenticatedPrincipal(
            $user->getId(),
            $refreshSession->getId(),
            $authenticationContext->getAuthenticationVersion(),
            $principalRoles,
            $principalPermissions
        );
    }

    /**
     * Rejects invalid or incomplete principal authority without disclosing which reference failed.
     *
     * @throws PrincipalResolutionException Always
     */
    private function deny(): never
    {
        throw new PrincipalResolutionException('The current principal authority is not valid.');
    }
}
