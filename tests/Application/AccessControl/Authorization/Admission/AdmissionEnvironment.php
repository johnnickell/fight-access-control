<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContext;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContextProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthoritativePrincipalResolver;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\CurrentPrincipalProvider;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use Throwable;

final class AdmissionEnvironment
{
    public int $resolutions = 0;

    public function user(
        bool $grant = true,
        bool $valid = true,
        ?Throwable $fault = null,
        ?PermissionId $permissionId = null
    ): RequestAuthority {
        $now = new DateTimeImmutable('2026-10-27T12:00:00Z');
        $transaction = new InMemoryUnitOfWork();
        $permissionRepository = new InMemoryPermissionRepository($transaction);
        $roleRepository = new InMemoryRoleRepository($transaction);
        $userRepository = new InMemoryUserRepository($transaction, getByIdFailure: $fault);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository();
        $permission = Permission::define(
            $permissionId ?? PermissionId::generate(),
            PermissionName::fromString('AGENT_PROFILE_READ'),
            $now
        );
        $permissionRepository->add($permission);
        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_READER'),
            $grant ? [$permission->getId()] : [],
            $now
        );
        $roleRepository->add($role);
        $user = UserFixture::withRoleAssignments([$role->getId()], 1);
        $userRepository->add($user);
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            RefreshCredential::fromString(str_repeat('a', 64)),
            $now->modify('-1 hour'),
            $now->modify('+1 hour'),
            $now->modify('+1 day'),
            1,
            false
        );
        if ($valid) {
            $refreshSessionRepository->add($session);
        }

        $claims = new AuthenticationContext($user->getId(), $session->getId(), 1);
        $context = new readonly class ($this, $claims) implements AuthenticationContextProvider {
            public function __construct(
                private AdmissionEnvironment $environment,
                private AuthenticationContext $claims
            ) {
            }

            public function getAuthenticationContext(): AuthenticationContext
            {
                ++$this->environment->resolutions;

                return $this->claims;
            }
        };

        return RequestAuthority::user(new CurrentPrincipalProvider(
            $context,
            new AuthoritativePrincipalResolver(
                $userRepository,
                $refreshSessionRepository,
                $roleRepository,
                $permissionRepository,
                new FixedClock($now)
            )
        ));
    }
}
