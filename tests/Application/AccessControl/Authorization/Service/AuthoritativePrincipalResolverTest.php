<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContext;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthoritativePrincipalResolver;
use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\PrincipalResolutionException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AuthenticationContext::class)]
#[CoversClass(AuthoritativePrincipalResolver::class)]
#[CoversClass(PrincipalResolutionException::class)]
final class AuthoritativePrincipalResolverTest extends TestCase
{
    private const string CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_it_resolves_all_authoritative_roles_and_deduplicated_permissions(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $sharedPermissionId = PermissionId::generate();
        $otherPermissionId = PermissionId::generate();
        $editor = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [$sharedPermissionId, $sharedPermissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $publisher = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_PUBLISHER'),
            [$sharedPermissionId, $otherPermissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->replaceRoleAssignments(
            [$editor->getId(), $publisher->getId()],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $permission = Permission::define(
            $sharedPermissionId,
            PermissionName::fromString('VIEW_ARTICLE'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $otherPermission = Permission::define(
            $otherPermissionId,
            PermissionName::fromString('PUBLISH_ARTICLE'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $session = $this->session($user->getId(), 7, $now);
        $resolver = $this->resolver($now, $user, $session, [$editor, $publisher], [$permission, $otherPermission]);
        $context = new AuthenticationContext($user->getId(), $session->getId(), 7);

        $principal = $resolver->resolve($context);

        self::assertSame($user->getId(), $principal->getUserId());
        self::assertSame($session->getId(), $principal->getRefreshSessionId());
        self::assertSame(7, $principal->getAuthenticationVersion());
        self::assertCount(2, $principal->getRoles());
        self::assertCount(2, $principal->getPermissions());
        self::assertTrue($principal->hasRole(RoleName::fromString('ROLE_EDITOR')));
        self::assertTrue($principal->hasPermission(PermissionName::fromString('PUBLISH_ARTICLE')));
        self::assertSame($editor->getId(), $principal->getRoles()[0]->getId());
        self::assertSame($permission->getId(), $principal->getPermissions()[0]->getId());
    }

    public function test_context_contains_only_authentication_authority_and_requires_a_positive_version(): void
    {
        $userId = UserId::generate();
        $sessionId = RefreshSessionId::generate();
        $context = new AuthenticationContext($userId, $sessionId, 3);

        self::assertSame($userId, $context->getUserId());
        self::assertSame($sessionId, $context->getRefreshSessionId());
        self::assertSame(3, $context->getAuthenticationVersion());
        $constructor = new ReflectionClass(AuthenticationContext::class)->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(
            ['userId', 'refreshSessionId', 'authenticationVersion'],
            array_map(static fn($parameter): string => $parameter->getName(), $constructor->getParameters())
        );

        $this->expectException(InvalidArgumentException::class);

        new AuthenticationContext($userId, $sessionId, 0);
    }

    public function test_it_denies_missing_user_or_session_authority(): void
    {
        $this->expectException(PrincipalResolutionException::class);
        $this->emptyResolver()->resolve(new AuthenticationContext(UserId::generate(), RefreshSessionId::generate(), 1));
    }

    public function test_it_denies_an_inactive_user(): void
    {
        $this->assertDenied(UserState::PENDING_ACTIVATION, 7, 7, 7);
    }

    public function test_it_denies_a_user_authentication_version_mismatch(): void
    {
        $this->assertDenied(UserState::ACTIVE, 7, 7, 8);
    }

    public function test_it_denies_a_session_authentication_version_mismatch(): void
    {
        $this->assertDenied(UserState::ACTIVE, 7, 6, 7);
    }

    public function test_it_denies_a_session_owned_by_another_user(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $session = $this->session(UserId::generate(), 7, $now);
        $resolver = $this->resolver($now, $user, $session);

        $this->expectException(PrincipalResolutionException::class);
        $resolver->resolve(new AuthenticationContext($user->getId(), $session->getId(), 7));
    }

    public function test_it_denies_a_revoked_session(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $session = $this->session($user->getId(), 7, $now)->revoke();
        $resolver = $this->resolver($now, $user, $session);

        $this->expectException(PrincipalResolutionException::class);
        $resolver->resolve(new AuthenticationContext($user->getId(), $session->getId(), 7));
    }

    public function test_it_denies_an_expired_session(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $session = $this->session($user->getId(), 7, $now, new DateTimeImmutable('2026-08-21T11:59:59+00:00'));
        $resolver = $this->resolver($now, $user, $session);

        $this->expectException(PrincipalResolutionException::class);
        $resolver->resolve(new AuthenticationContext($user->getId(), $session->getId(), 7));
    }

    public function test_it_denies_when_any_assigned_role_is_missing(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $user->replaceRoleAssignments([RoleId::generate()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $session = $this->session($user->getId(), 7, $now);

        $this->expectException(PrincipalResolutionException::class);
        $this->resolver($now, $user, $session)->resolve(
            new AuthenticationContext($user->getId(), $session->getId(), 7)
        );
    }

    public function test_it_denies_when_a_bulk_role_result_is_not_assigned(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $user->replaceRoleAssignments([RoleId::generate()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $session = $this->session($user->getId(), 7, $now);
        $unexpectedRole = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_UNEXPECTED'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $roleRepository = $this->createStub(RoleRepository::class);
        $roleRepository->method('getByIds')->willReturn([$unexpectedRole]);

        $this->expectException(PrincipalResolutionException::class);
        $this->resolver($now, $user, $session, roleRepository: $roleRepository)->resolve(
            new AuthenticationContext($user->getId(), $session->getId(), 7)
        );
    }

    public function test_it_denies_when_any_referenced_permission_is_missing(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [PermissionId::generate()],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->replaceRoleAssignments([$role->getId()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $session = $this->session($user->getId(), 7, $now);

        $this->expectException(PrincipalResolutionException::class);
        $this->resolver($now, $user, $session, [$role])->resolve(
            new AuthenticationContext($user->getId(), $session->getId(), 7)
        );
    }

    public function test_it_denies_when_a_bulk_permission_result_is_not_referenced(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = $this->activeUser(7);
        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [PermissionId::generate()],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->replaceRoleAssignments([$role->getId()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $session = $this->session($user->getId(), 7, $now);
        $unexpectedPermission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('UNEXPECTED_PERMISSION'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $permissionRepository = $this->createStub(PermissionRepository::class);
        $permissionRepository->method('getByIds')->willReturn([$unexpectedPermission]);

        $this->expectException(PrincipalResolutionException::class);
        $this->resolver(
            $now,
            $user,
            $session,
            [$role],
            permissionRepository: $permissionRepository
        )->resolve(new AuthenticationContext($user->getId(), $session->getId(), 7));
    }

    private function activeUser(int $authenticationVersion): User
    {
        return UserFixture::withIdAndAuthenticationVersion(
            UserId::generate(),
            'principal@example.test',
            UserState::ACTIVE,
            $authenticationVersion
        );
    }

    private function assertDenied(
        UserState $state,
        int $userAuthenticationVersion,
        int $sessionAuthenticationVersion,
        int $contextAuthenticationVersion
    ): void {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $user = UserFixture::withIdAndAuthenticationVersion(
            UserId::generate(),
            'denied@example.test',
            $state,
            $userAuthenticationVersion
        );
        $session = $this->session($user->getId(), $sessionAuthenticationVersion, $now);
        $resolver = $this->resolver($now, $user, $session);

        try {
            $resolver->resolve(
                new AuthenticationContext($user->getId(), $session->getId(), $contextAuthenticationVersion)
            );
            self::fail('Expected principal resolution to fail closed.');
        } catch (PrincipalResolutionException $principalResolutionException) {
            self::assertSame(
                'The current principal authority is not valid.',
                $principalResolutionException->getMessage()
            );
        }
    }

    private function emptyResolver(): AuthoritativePrincipalResolver
    {
        return new AuthoritativePrincipalResolver(
            new InMemoryUserRepository(),
            new InMemoryRefreshSessionRepository(),
            new InMemoryRoleRepository(),
            new InMemoryPermissionRepository(),
            new FixedClock(new DateTimeImmutable('2026-08-21T12:00:00+00:00'))
        );
    }

    /**
     * @param list<Role>       $roles
     * @param list<Permission> $permissions
     */
    private function resolver(
        DateTimeImmutable $now,
        User $user,
        RefreshSession $session,
        array $roles = [],
        array $permissions = [],
        ?RoleRepository $roleRepository = null,
        ?PermissionRepository $permissionRepository = null
    ): AuthoritativePrincipalResolver {
        $userRepository = new InMemoryUserRepository();
        $sessionRepository = new InMemoryRefreshSessionRepository();
        $inMemoryRoleRepository = new InMemoryRoleRepository();
        $inMemoryPermissionRepository = new InMemoryPermissionRepository();
        $userRepository->add($user);
        $sessionRepository->add($session);
        foreach ($roles as $role) {
            $inMemoryRoleRepository->add($role);
        }

        foreach ($permissions as $permission) {
            $inMemoryPermissionRepository->add($permission);
        }

        return new AuthoritativePrincipalResolver(
            $userRepository,
            $sessionRepository,
            $roleRepository ?? $inMemoryRoleRepository,
            $permissionRepository ?? $inMemoryPermissionRepository,
            new FixedClock($now)
        );
    }

    private function session(
        UserId $userId,
        int $authenticationVersion,
        DateTimeImmutable $now,
        ?DateTimeImmutable $idleExpiresAt = null
    ): RefreshSession {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $userId,
            RefreshCredential::fromString(self::CREDENTIAL),
            $now->sub(new DateInterval('PT1H')),
            $idleExpiresAt ?? $now->add(new DateInterval('PT1H')),
            $now->add(new DateInterval('P1D')),
            $authenticationVersion,
            false
        );
    }
}
