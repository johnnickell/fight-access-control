<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContext;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContextProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthoritativePrincipalResolver;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\CurrentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\User\Service\AuthenticationClock;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(CurrentPrincipalProvider::class)]
final class CurrentPrincipalProviderTest extends TestCase
{
    public function test_consumer_port_supplies_only_a_no_argument_authentication_context(): void
    {
        $method = new ReflectionMethod(AuthenticationContextProvider::class, 'getAuthenticationContext');

        self::assertSame(0, $method->getNumberOfParameters());
        self::assertSame(AuthenticationContext::class, (string) $method->getReturnType());
        self::assertSame(['getAuthenticationContext'], get_class_methods(AuthenticationContextProvider::class));
    }

    public function test_package_provider_resolves_authoritatively_once_and_caches_for_its_request_scope(): void
    {
        $now = new DateTimeImmutable('2026-08-21T12:00:00+00:00');
        $userId = UserId::generate();
        $user = UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'current-principal@example.test',
            UserState::ACTIVE,
            3
        );
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            $userId,
            RefreshCredential::fromString(
                '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
            ),
            $now->sub(new DateInterval('PT1H')),
            $now->add(new DateInterval('PT1H')),
            $now->add(new DateInterval('P1D')),
            3,
            false
        );
        $context = new AuthenticationContext($userId, $session->getId(), 3);
        $authenticationContextProvider = new class ($context) implements AuthenticationContextProvider {
            public int $calls = 0;

            public function __construct(private readonly AuthenticationContext $authenticationContext)
            {
            }

            public function getAuthenticationContext(): AuthenticationContext
            {
                ++$this->calls;

                return $this->authenticationContext;
            }
        };
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())->method('getById')->with($userId)->willReturn($user);
        $refreshSessionRepository = $this->createMock(RefreshSessionRepository::class);
        $refreshSessionRepository->expects(self::once())
            ->method('getById')
            ->with($session->getId())
            ->willReturn($session);
        $roleRepository = $this->createMock(RoleRepository::class);
        $roleRepository->expects(self::once())->method('getByIds')->with([])->willReturn([]);
        $permissionRepository = $this->createMock(PermissionRepository::class);
        $permissionRepository->expects(self::once())->method('getByIds')->with([])->willReturn([]);
        $clock = $this->createStub(AuthenticationClock::class);
        $clock->method('now')->willReturn($now);
        $provider = new CurrentPrincipalProvider(
            $authenticationContextProvider,
            new AuthoritativePrincipalResolver(
                $userRepository,
                $refreshSessionRepository,
                $roleRepository,
                $permissionRepository,
                $clock
            )
        );

        $first = $provider->getCurrentPrincipal();
        $second = $provider->getCurrentPrincipal();

        self::assertSame($first, $second);
        self::assertSame(1, $authenticationContextProvider->calls);
    }

    public function test_package_provider_has_no_authenticated_principal_input_seam(): void
    {
        $constructor = new ReflectionClass(CurrentPrincipalProvider::class)->getConstructor();
        self::assertNotNull($constructor);

        $parameterTypes = array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $constructor->getParameters()
        );

        self::assertSame(
            [AuthenticationContextProvider::class, AuthoritativePrincipalResolver::class],
            $parameterTypes
        );
        self::assertNotContains(AuthenticatedPrincipal::class, $parameterTypes);
    }
}
