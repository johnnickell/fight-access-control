<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentAuthenticationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnostic;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CurrentAgentPrincipalProvider::class)]
#[CoversClass(AgentAuthenticationDiagnostic::class)]
#[CoversClass(AgentAuthenticationDiagnosticClassification::class)]
#[CoversClass(CurrentAgentPrincipalResolutionRejectedException::class)]
final class CurrentAgentPrincipalProviderTest extends TestCase
{
    public function test_that_it_resolves_one_complete_immutable_principal_once_per_request(): void
    {
        $now = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $agentId = AgentId::fromString('018f0000-0000-7000-8000-000000000001');
        $credentialId = AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002');
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000003');
        $agent = Agent::provision(
            $agentId,
            AgentName::fromString('Build agent'),
            $credentialId,
            'encrypted:current-secret',
            $now
        )->grantPermission($permissionId, $now);
        $request = new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1',
            $now,
            'nonce-0001',
            $credentialId,
            'HMAC-SHA256',
            'valid-signature',
            null,
            ''
        );
        $agents = new InMemoryAgentRepository();
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository();
        $permissions->add(Permission::define(
            $permissionId,
            PermissionName::fromString('VIEW_AGENTS'),
            $now
        ));
        $unitOfWork = new InMemoryUnitOfWork();
        $authentication = new AgentAuthenticationService(
            $agents,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'current-secret'),
            new FixedClock($now),
            new InMemoryAgentRequestNonceConsumer($agents, $unitOfWork),
            $unitOfWork
        );
        $provider = new CurrentAgentPrincipalProvider($authentication, $agents, $permissions);

        $first = $provider->resolve($request, 'correlation-0001');
        $second = $provider->resolve($request, 'correlation-0001');

        self::assertSame($first, $second);
        self::assertSame(
            [
                'agent_id' => '018f0000-0000-7000-8000-000000000001',
                'credential_id' => '018f0000-0000-7000-8000-000000000002',
                'credential_revision' => 0,
                'permission_assignment_revision' => 2,
                'permissions' => [
                    [
                        'permission_id' => '018f0000-0000-7000-8000-000000000003',
                        'name' => 'VIEW_AGENTS',
                    ],
                ],
            ],
            $first->toArray()
        );
    }

    public function test_that_it_returns_one_generic_denial_and_safe_diagnostic_for_a_rejected_signature(): void
    {
        [$provider, $request] = $this->provider('expected-signature');
        $rejectedRequest = new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1',
            new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            'nonce-0001',
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002'),
            'HMAC-SHA256',
            'rejected-signature',
            null,
            ''
        );

        try {
            $provider->resolve($rejectedRequest, 'correlation-0002');
            self::fail('A rejected signature must not resolve a principal.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                'Agent authentication rejected.',
                $currentAgentPrincipalResolutionRejectedException->getMessage()
            );
            self::assertSame(
                [
                    'classification' => 'authentication_rejected',
                    'correlation_id' => 'correlation-0002',
                ],
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->toArray()
            );
            $diagnostic = json_encode($currentAgentPrincipalResolutionRejectedException->getDiagnostic()->toArray());
            self::assertStringNotContainsString('rejected-signature', $diagnostic);
            self::assertStringNotContainsString($request->getNonce(), $diagnostic);
            self::assertStringNotContainsString('current-secret', $diagnostic);
            self::assertStringNotContainsString($request->getAuthority(), $diagnostic);
        }
    }

    public function test_that_it_denies_when_the_post_authentication_agent_authority_is_not_current(): void
    {
        [$authentication, $request, $permissions] = $this->authentication('valid-signature');
        $provider = new CurrentAgentPrincipalProvider(
            $authentication,
            new InMemoryAgentRepository(),
            $permissions
        );

        try {
            $provider->resolve($request, 'correlation-0003');
            self::fail('Missing post-authentication Agent authority must be denied.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                'Agent authentication rejected.',
                $currentAgentPrincipalResolutionRejectedException->getMessage()
            );
            self::assertSame(
                AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT,
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification()
            );
            self::assertSame(
                'correlation-0003',
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getCorrelationId()
            );
        }
    }

    public function test_that_it_denies_a_permission_assignment_replaced_after_authentication(): void
    {
        [$authentication, $request] = $this->authentication('valid-signature');
        $now = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $replacementPermissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000005');
        $providerAgents = new InMemoryAgentRepository();
        $providerAgents->add($this->agent()->replacePermissions(
            [$replacementPermissionId],
            2,
            $now
        ));
        $permissions = new InMemoryPermissionRepository();
        $permissions->add(Permission::define(
            $replacementPermissionId,
            PermissionName::fromString('MANAGE_AGENTS'),
            $now
        ));
        $provider = new CurrentAgentPrincipalProvider($authentication, $providerAgents, $permissions);

        try {
            $provider->resolve($request, 'correlation-stale-assignment-fence');
            self::fail('A replaced Permission assignment must not resolve a replacement principal.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                'Agent authentication rejected.',
                $currentAgentPrincipalResolutionRejectedException->getMessage()
            );
            self::assertSame(
                AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT,
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification()
            );
            self::assertSame(
                'correlation-stale-assignment-fence',
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getCorrelationId()
            );
        }
    }

    public function test_that_it_denies_an_incomplete_or_mismatched_permission_snapshot(): void
    {
        foreach (['incomplete', 'mismatched'] as $case) {
            [$authentication, $request] = $this->authentication('valid-signature');
            $permissions = new InMemoryPermissionRepository(
                getByIdsResult: static fn(array $ids): array => $case === 'incomplete' ? [] : [
                    Permission::define(
                        PermissionId::fromString('018f0000-0000-7000-8000-000000000004'),
                        PermissionName::fromString('UNASSIGNED_PERMISSION'),
                        new DateTimeImmutable('2026-08-26T12:00:00+00:00')
                    ),
                ]
            );
            $agentRepository = new InMemoryAgentRepository();
            $agentRepository->add($this->agent());
            $provider = new CurrentAgentPrincipalProvider($authentication, $agentRepository, $permissions);

            try {
                $provider->resolve($request, 'correlation-'.$case);
                self::fail(sprintf('%s Permission snapshot must be denied.', $case));
            } catch (CurrentAgentPrincipalResolutionRejectedException $exception) {
                self::assertSame('Agent authentication rejected.', $exception->getMessage());
                self::assertSame(
                    AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID,
                    $exception->getDiagnostic()->getClassification()
                );
                self::assertSame('correlation-'.$case, $exception->getDiagnostic()->getCorrelationId());
            }
        }
    }

    public function test_that_it_fails_closed_when_authoritative_permission_resolution_fails(): void
    {
        [$authentication, $request] = $this->authentication('valid-signature');
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add($this->agent());

        $permissions = new InMemoryPermissionRepository(
            getByIdsResult: static function (): array {
                throw new RuntimeException('Persistence unavailable.');
            }
        );
        $provider = new CurrentAgentPrincipalProvider($authentication, $agentRepository, $permissions);

        try {
            $provider->resolve($request, 'correlation-0004');
            self::fail('A failed authoritative Permission read must be denied.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                'Agent authentication rejected.',
                $currentAgentPrincipalResolutionRejectedException->getMessage()
            );
            self::assertSame(
                AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED,
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification()
            );
            self::assertSame(
                'correlation-0004',
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getCorrelationId()
            );
        }
    }

    public function test_that_a_diagnostic_requires_a_correlation_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AgentAuthenticationDiagnostic(
            AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
            ' '
        );
    }

    /**
     * @return array{0: CurrentAgentPrincipalProvider, 1: SignedAgentRequest}
     */
    private function provider(string $signature): array
    {
        [$authentication, $request, $permissions] = $this->authentication($signature);
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add($this->agent());

        return [new CurrentAgentPrincipalProvider($authentication, $agentRepository, $permissions), $request];
    }

    /**
     * @return array{0: AgentAuthenticationService, 1: SignedAgentRequest, 2: InMemoryPermissionRepository}
     */
    private function authentication(string $signature): array
    {
        $now = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $request = new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1',
            $now,
            'nonce-0001',
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002'),
            'HMAC-SHA256',
            $signature,
            null,
            ''
        );
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add($this->agent());

        $permissions = new InMemoryPermissionRepository();
        $permissions->add(Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000003'),
            PermissionName::fromString('VIEW_AGENTS'),
            $now
        ));
        $unitOfWork = new InMemoryUnitOfWork();

        return [
            new AgentAuthenticationService(
                $agentRepository,
                new FixedHmacSharedSecretDecipher('encrypted:'),
                new FixedHmacSignedAgentRequestVerifier($request, 'current-secret'),
                new FixedClock($now),
                new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork),
                $unitOfWork
            ),
            $request,
            $permissions,
        ];
    }

    private function agent(): Agent
    {
        $now = new DateTimeImmutable('2026-08-26T12:00:00+00:00');

        return Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('Build agent'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002'),
            'encrypted:current-secret',
            $now
        )->grantPermission(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000003'),
            $now
        );
    }
}
