<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
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
    public function test_that_it_resolves_and_caches_one_complete_principal_after_one_nonce_consumption(): void
    {
        [$provider, $request, $nonceConsumer, $unitOfWork] = $this->provider();

        $first = $provider->resolve($request, 'correlation-success');
        $second = $provider->resolve($request, 'correlation-success');

        self::assertSame($first, $second);
        self::assertSame(1, $nonceConsumer->consumptionCalls());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(
            '2026-08-26T12:05:00+00:00',
            $nonceConsumer->expiresAt()?->format(DATE_ATOM)
        );
        self::assertSame('VIEW_AGENTS', $first->toArray()['permissions'][0]['name']);
    }

    public function test_that_invalid_requests_do_not_consume_a_nonce_and_retain_only_safe_diagnostics(): void
    {
        foreach (
            [
                $this->request(signature: 'wrong-signature'),
                $this->request(body: 'body', bodyDigest: null, deriveBodyDigest: false),
            ] as $request
        ) {
            [$provider, , $nonceConsumer] = $this->provider();

            try {
                $provider->resolve($request, 'correlation-invalid');
                self::fail('Invalid requests must fail closed.');
            } catch (CurrentAgentPrincipalResolutionRejectedException $exception) {
                self::assertSame('Agent authentication rejected.', $exception->getMessage());
                self::assertSame(
                    AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
                    $exception->getDiagnostic()->getClassification()
                );
                self::assertSame('correlation-invalid', $exception->getDiagnostic()->getCorrelationId());
                self::assertStringNotContainsString(
                    $request->getSignature(),
                    json_encode($exception->getDiagnostic()->toArray())
                );
                self::assertStringNotContainsString(
                    $request->getNonce(),
                    json_encode($exception->getDiagnostic()->toArray())
                );
            }

            self::assertSame(0, $nonceConsumer->consumptionCalls());
        }
    }

    public function test_that_a_replayed_valid_request_crosses_the_nonce_boundary_only_once(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agents = $this->agents();
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer($agents, $unitOfWork);
        [$firstProvider, $request] = $this->provider($agents, null, $unitOfWork, $nonceConsumer);
        [$secondProvider] = $this->provider($agents, null, $unitOfWork, $nonceConsumer);

        $firstProvider->resolve($request, 'correlation-first');

        $this->expectException(CurrentAgentPrincipalResolutionRejectedException::class);

        try {
            $secondProvider->resolve($request, 'correlation-replay');
        } finally {
            self::assertSame(2, $nonceConsumer->consumptionCalls());
        }
    }

    public function test_that_post_boundary_authority_failures_do_not_restore_the_nonce(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agents = $this->agents();
        $agent = $agents->getByCredentialId($this->credentialId());
        self::assertInstanceOf(Agent::class, $agent);
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer(
            $agents,
            $unitOfWork,
            afterConsume: function () use ($agents, $agent): void {
                $agents->replace($agent, $agent->revoke($this->now()));
            }
        );
        [$provider, $request] = $this->provider($agents, null, $unitOfWork, $nonceConsumer);

        try {
            $provider->resolve($request, 'correlation-post-boundary');
            self::fail('A changed Agent authority must not return a principal.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT,
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification()
            );
        }

        self::assertSame(1, $nonceConsumer->consumptionCalls());
    }

    public function test_that_permission_resolution_failures_do_not_return_a_partial_principal(): void
    {
        $permissions = new InMemoryPermissionRepository(
            getByIdsResult: static function (): array {
                throw new RuntimeException('Permission persistence unavailable.');
            }
        );
        [$provider, $request, $nonceConsumer] = $this->provider(null, $permissions);

        try {
            $provider->resolve($request, 'correlation-permissions');
            self::fail('An incomplete Permission snapshot must fail closed.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertSame(
                AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED,
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->getClassification()
            );
        }

        self::assertSame(1, $nonceConsumer->consumptionCalls());
    }

    public function test_that_a_diagnostic_requires_a_correlation_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AgentAuthenticationDiagnostic(
            AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
            ' '
        );
    }

    /** @return array{0: CurrentAgentPrincipalProvider, 1: SignedAgentRequest, 2: InMemoryAgentRequestNonceConsumer, 3: InMemoryUnitOfWork} */
    private function provider(
        ?InMemoryAgentRepository $agents = null,
        ?InMemoryPermissionRepository $permissions = null,
        ?InMemoryUnitOfWork $unitOfWork = null,
        ?InMemoryAgentRequestNonceConsumer $nonceConsumer = null
    ): array {
        $agents ??= $this->agents();
        $permissions ??= $this->permissions();
        $unitOfWork ??= new InMemoryUnitOfWork();
        $nonceConsumer ??= new InMemoryAgentRequestNonceConsumer($agents, $unitOfWork);
        $request = $this->request();

        return [new CurrentAgentPrincipalProvider(
            $agents,
            $permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'current-secret'),
            new FixedClock($this->now()),
            $nonceConsumer,
            $unitOfWork
        ), $request, $nonceConsumer, $unitOfWork];
    }

    private function agents(): InMemoryAgentRepository
    {
        $agents = new InMemoryAgentRepository();
        $agents->add(Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('Build agent'),
            $this->credentialId(),
            'encrypted:current-secret',
            $this->now()
        )->grantPermission($this->permissionId(), $this->now()));

        return $agents;
    }

    private function permissions(): InMemoryPermissionRepository
    {
        $permissions = new InMemoryPermissionRepository();
        $permissions->add(Permission::define(
            $this->permissionId(),
            PermissionName::fromString('VIEW_AGENTS'),
            $this->now()
        ));

        return $permissions;
    }

    private function request(
        string $signature = 'valid-signature',
        string $body = '',
        ?string $bodyDigest = null,
        bool $deriveBodyDigest = true
    ): SignedAgentRequest {
        return new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1',
            $this->now(),
            'nonce-0001',
            $this->credentialId(),
            'HMAC-SHA256',
            $signature,
            $body === '' ? $bodyDigest : ($deriveBodyDigest ? ($bodyDigest ?? hash('sha256', $body)) : $bodyDigest),
            $body
        );
    }

    private function credentialId(): AgentCredentialId
    {
        return AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002');
    }

    private function permissionId(): PermissionId
    {
        return PermissionId::fromString('018f0000-0000-7000-8000-000000000003');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26T12:00:00+00:00');
    }
}
