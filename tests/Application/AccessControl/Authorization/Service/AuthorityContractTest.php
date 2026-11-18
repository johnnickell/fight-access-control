<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\AgentRequestNonceConsumer;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\HmacSharedSecretDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\HmacSignedAgentRequestVerifier;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContext;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContextProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthoritativePrincipalResolver;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\CurrentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Throwable;
use UnexpectedValueException;

#[CoversClass(AuthoritativePrincipalResolver::class)]
#[CoversClass(ExactPermissionResolver::class)]
#[CoversClass(CurrentAgentPrincipalProvider::class)]
#[CoversClass(RequestAuthority::class)]
final class AuthorityContractTest extends TestCase
{
    public function test_wrong_user_or_session_identity_is_an_internal_fault_not_authentication_refusal(): void
    {
        foreach (['user', 'session'] as $wrong) {
            $user = UserFixture::withRoleAssignments([], 1);
            $session = $this->session($user->getId());
            $claims = new AuthenticationContext(
                $wrong === 'user' ? UserId::generate() : $user->getId(),
                $wrong === 'session' ? RefreshSessionId::generate() : $session->getId(),
                1
            );
            $userRepository = $this->createStub(UserRepository::class);
            $userRepository->method('getById')->willReturn($user);
            $sessionRepository = $this->createStub(RefreshSessionRepository::class);
            $sessionRepository->method('getById')->willReturn($session);
            $contextProvider = $this->createStub(AuthenticationContextProvider::class);
            $contextProvider->method('getAuthenticationContext')->willReturn($claims);
            $authority = RequestAuthority::user(new CurrentPrincipalProvider(
                $contextProvider,
                new AuthoritativePrincipalResolver(
                    $userRepository,
                    $sessionRepository,
                    $this->createStub(RoleRepository::class),
                    $this->createStub(PermissionRepository::class),
                    new FixedClock($this->now())
                )
            ));
            $this->assertHttpOutcome($authority->resolve(...), 500);
            try {
                $authority->resolve();
                self::fail('A mismatched repository result cannot authenticate.');
            } catch (UnexpectedValueException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
    }

    public function test_role_snapshot_contract_violations_are_not_expected_authentication_outcomes(): void
    {
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_READER'), [], $this->now());
        $sameName = Role::define(RoleId::generate(), $role->getName(), [], $this->now());
        foreach ([[], [$role, $role], [$sameName], ['key' => $role], [null], [$role, $sameName]] as $roles) {
            $assignedIds = [$role->getId()];
            if ($roles === [$role, $sameName]) {
                $assignedIds[] = $sameName->getId();
            }

            $user = UserFixture::withRoleAssignments($assignedIds, 1);
            $session = $this->session($user->getId());
            $userRepository = $this->createStub(UserRepository::class);
            $userRepository->method('getById')->willReturn($user);
            $sessionRepository = $this->createStub(RefreshSessionRepository::class);
            $sessionRepository->method('getById')->willReturn($session);
            $roleRepository = $this->createStub(RoleRepository::class);
            $roleRepository->method('getByIds')->willReturn($roles);
            try {
                new AuthoritativePrincipalResolver(
                    $userRepository,
                    $sessionRepository,
                    $roleRepository,
                    $this->createStub(PermissionRepository::class),
                    new FixedClock($this->now())
                )->resolve(new AuthenticationContext($user->getId(), $session->getId(), 1));
                self::fail('Broken complete Role sets must fail internally.');
            } catch (UnexpectedValueException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
    }

    public function test_reserved_role_name_lookup_is_validated_even_without_assigned_roles(): void
    {
        $user = UserFixture::withRoleAssignments([], 1);
        $session = $this->session($user->getId());
        $users = $this->createStub(UserRepository::class);
        $users->method('getById')->willReturn($user);
        $sessions = $this->createStub(RefreshSessionRepository::class);
        $sessions->method('getById')->willReturn($session);
        $roles = $this->createStub(RoleRepository::class);
        $roles->method('getByIds')->willReturn([]);
        $roles->method('getByName')->willReturn(Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_READER'),
            [],
            $this->now()
        ));
        $this->expectException(ManagedRoleException::class);
        new AuthoritativePrincipalResolver(
            $users,
            $sessions,
            $roles,
            $this->createStub(PermissionRepository::class),
            new FixedClock($this->now())
        )->resolve(new AuthenticationContext($user->getId(), $session->getId(), 1));
    }

    public function test_exact_permission_resolution_rejects_malformed_lists_and_duplicate_names(): void
    {
        $first = Permission::define(PermissionId::generate(), PermissionName::fromString('READ_RECORD'), $this->now());
        $second = Permission::define(PermissionId::generate(), $first->getName(), $this->now());
        foreach ([['key' => $first], [null], [$first, $second]] as $definitions) {
            $repository = $this->createStub(PermissionRepository::class);
            $repository->method('getByIds')->willReturn($definitions);
            try {
                new ExactPermissionResolver($repository)->resolve(array_map(
                    static fn(Permission $permission): PermissionId => $permission->getId(),
                    count($definitions) === 2 ? [$first, $second] : [$first]
                ));
                self::fail('Invalid authoritative definitions cannot become a snapshot.');
            } catch (ExactPermissionResolutionException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
    }

    public function test_wrong_credential_lookup_never_reaches_crypto_or_nonce_and_is_not_a_known_denial(): void
    {
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Contract agent'),
            AgentCredentialId::generate(),
            'encrypted fixture',
            $this->now()
        );
        $repository = $this->createStub(AgentRepository::class);
        $repository->method('getByCredentialId')->willReturn($agent);
        $decipher = $this->createMock(HmacSharedSecretDecipher::class);
        $decipher->expects(self::never())->method('decrypt');
        $nonce = $this->createMock(AgentRequestNonceConsumer::class);
        $nonce->expects(self::never())->method('consume');
        $provider = new CurrentAgentPrincipalProvider(
            $repository,
            $this->createStub(PermissionRepository::class),
            $decipher,
            $this->createStub(HmacSignedAgentRequestVerifier::class),
            new FixedClock($this->now()),
            $nonce,
            new InMemoryUnitOfWork()
        );
        $request = new SignedAgentRequest(
            'GET',
            'fixture.example',
            '/',
            '',
            $this->now(),
            'fixture nonce',
            AgentCredentialId::generate(),
            'HMAC-SHA256',
            'fixture signature',
            null,
            ''
        );
        try {
            RequestAuthority::agent($provider, $request, 'safe-contract')->resolve();
            self::fail('A foreign credential lookup must fail internally.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED,
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification()
            );
            self::assertNull($currentAgentPrincipalResolutionRejectedException->getPrevious());
        }
    }

    public function test_post_nonce_internal_failures_do_not_restore_replay_authority(): void
    {
        foreach (['wrong-agent', 'missing-permission', 'protected-permission', 'storage-fault'] as $mode) {
            $permissionId = PermissionId::generate();
            $agent = Agent::provision(
                AgentId::generate(),
                AgentName::fromString('Nonce fixture'),
                AgentCredentialId::generate(),
                'encrypted:fixture',
                $this->now()
            )->grantPermission($permissionId, $this->now());
            $storage = new InMemoryAgentRepository();
            $storage->add($agent);
            $unitOfWork = new InMemoryUnitOfWork();
            $nonces = new InMemoryAgentRequestNonceConsumer($storage, $unitOfWork);
            $repository = $this->createStub(AgentRepository::class);
            $repository->method('getByCredentialId')->willReturn($agent);
            $foreign = Agent::provision(
                AgentId::generate(),
                AgentName::fromString('Foreign fixture'),
                AgentCredentialId::generate(),
                'encrypted:fixture',
                $this->now()
            );
            $repository->method('getById')->willReturn($mode === 'wrong-agent' ? $foreign : $agent);
            $permissions = $this->createStub(PermissionRepository::class);
            if ($mode === 'storage-fault') {
                $permissions->method('getByIds')->willThrowException(new RuntimeException('private fixture'));
            } elseif ($mode === 'protected-permission') {
                $permissions->method('getByIds')->willReturn([Permission::defineManaged(
                    $permissionId,
                    PermissionName::fromString('PROTECTED_AUTHORITY'),
                    PermissionTier::SUPER_ADMIN_ONLY,
                    $this->now()
                )]);
            } else {
                $permissions->method('getByIds')->willReturn([]);
            }

            $request = new SignedAgentRequest(
                'GET',
                'fixture.example',
                '/',
                '',
                $this->now(),
                'nonce fixture',
                $agent->getCredentialId(),
                'HMAC-SHA256',
                'fixture signature',
                null,
                ''
            );
            $newProvider = fn(): CurrentAgentPrincipalProvider => new CurrentAgentPrincipalProvider(
                $repository,
                $permissions,
                new FixedHmacSharedSecretDecipher('encrypted:'),
                new FixedHmacSignedAgentRequestVerifier($request, 'fixture'),
                new FixedClock($this->now()),
                $nonces,
                $unitOfWork
            );
            foreach ([false, true] as $replay) {
                $provider = $newProvider();
                try {
                    RequestAuthority::agent($provider, $request, 'safe-correlation')->resolve();
                    self::fail('Neither a partial principal nor nonce replay may authenticate.');
                } catch (AuthenticationRequired) {
                    self::assertTrue($replay);
                } catch (CurrentAgentPrincipalResolutionRejectedException $failure) {
                    self::assertFalse($replay);
                    $expected = AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED;
                    if (in_array($mode, ['missing-permission', 'protected-permission'], true)) {
                        $expected = AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID;
                    }

                    self::assertSame(
                        $expected,
                        $failure->getDiagnostic()->getClassification()
                    );
                    self::assertNull($failure->getPrevious());
                }

                self::assertSame($replay ? 2 : 1, $nonces->consumptionCalls());
            }
        }
    }

    public function test_real_request_admission_composes_distinct_safe_http_outcomes(): void
    {
        $declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ')
        ]);
        $admission = new PermissionAdmission();
        $this->assertHttpOutcome(fn(): ?SecurityContext => $admission->admit(
            $declaration,
            new AdmissionEnvironment()->user(valid: false)
        ), 401);
        $this->assertHttpOutcome(fn(): ?SecurityContext => $admission->admit(
            $declaration,
            new AdmissionEnvironment()->user(grant: false)
        ), 403);
        $this->assertHttpOutcome(fn(): ?SecurityContext => $admission->admit(
            $declaration,
            new AdmissionEnvironment()->user(fault: new RuntimeException('private fixture fault', 401))
        ), 500);
        $this->assertHttpOutcome(fn(): ?SecurityContext => $admission->admit(
            $declaration,
            new AdmissionEnvironment()->user()
        ), 204);
    }

    /** @phpstan-param Closure(): mixed $execute */
    private function assertHttpOutcome(Closure $execute, int $status): void
    {
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects($status === 204 ? self::never() : self::once())->method('report');
        $projector = new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if ($status === 401) {
                    return new SafeHttpPresentation(
                        metadata: new SafeHttpMetadata(challenge: 'Signature realm="fixture"')
                    );
                }

                return null;
            }
        };
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics,
            $projector
        );
        $handler = new readonly class ($execute) implements RequestHandlerInterface {
            /** @phpstan-param Closure(): mixed $execute */
            public function __construct(private Closure $execute)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->execute)();

                return new ResponseFactory()->createResponse(204);
            }
        };
        $response = $middleware->process(new ServerRequestFactory()->createServerRequest('GET', '/fixture'), $handler);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('private', (string) $response->getBody());
        self::assertStringNotContainsString('trace', (string) $response->getBody());
    }

    private function session(UserId $userId): RefreshSession
    {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $userId,
            RefreshCredential::fromString(str_repeat('a', 64)),
            $this->now()->modify('-1 hour'),
            $this->now()->modify('+1 hour'),
            $this->now()->modify('+1 day'),
            1,
            false
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-08T12:00:00Z');
    }
}
