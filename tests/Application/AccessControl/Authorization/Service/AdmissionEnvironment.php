<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContext;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContextProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthoritativePrincipalResolver;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\CurrentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\RequestAuthority;
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

    public function user(bool $grant = true, bool $valid = true, ?Throwable $fault = null): RequestAuthority
    {
        $now = new DateTimeImmutable('2026-10-27T12:00:00Z');
        $transaction = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($transaction);
        $roles = new InMemoryRoleRepository($transaction);
        $users = new InMemoryUserRepository($transaction, getByIdFailure: $fault);
        $sessions = new InMemoryRefreshSessionRepository();
        $permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('AGENT_PROFILE_READ'),
            $now
        );
        $permissions->add($permission);
        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_READER'),
            $grant ? [$permission->getId()] : [],
            $now
        );
        $roles->add($role);
        $user = UserFixture::withRoleAssignments([$role->getId()], 1);
        $users->add($user);
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
            $sessions->add($session);
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
            new AuthoritativePrincipalResolver($users, $sessions, $roles, $permissions, new FixedClock($now))
        ));
    }
}
