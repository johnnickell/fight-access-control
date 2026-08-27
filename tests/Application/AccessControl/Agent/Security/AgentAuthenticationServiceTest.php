<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentAuthenticationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSignedAgentRequestVerifier;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Auth\Hmac\HmacRequestService;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FightCommonHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

#[CoversClass(AgentAuthenticationService::class)]
#[CoversClass(AgentAuthenticationRejectedException::class)]
#[AllowMockObjectsWithoutExpectations]
final class AgentAuthenticationServiceTest extends TestCase
{
    public function test_that_it_authenticates_a_request_signed_and_verified_by_fight_common_hmac(): void
    {
        $credentialId = AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002');
        $hmacSharedSecret = '0123456789abcdef';
        $body = 'request-body';
        $headers = [];
        $normalizedUri = $this->createMock(UriInterface::class);
        $normalizedUri->method('getAuthority')->willReturn('api.fight.example');
        $normalizedUri->method('getPath')->willReturn('/v1/agents');
        $normalizedUri->method('getQuery')->willReturn('page=1&sort=created_at');
        $sourceUri = $this->createMock(UriInterface::class);
        $sourceUri->method('getQuery')->willReturn('sort=created_at&page=1');
        $sourceUri->expects($this->once())
            ->method('withQuery')
            ->with('page=1&sort=created_at')
            ->willReturn($normalizedUri);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $outboundRequest = $this->createMock(RequestInterface::class);
        $outboundRequest->method('getMethod')->willReturn('POST');
        $outboundRequest->method('getUri')->willReturn($sourceUri);
        $outboundRequest->method('getBody')->willReturn($stream);
        $outboundRequest->expects($this->once())
            ->method('withUri')
            ->with($normalizedUri)
            ->willReturn($outboundRequest);
        $outboundRequest->expects($this->exactly(6))->method('withHeader')->willReturnCallback(
            static function (string $name, int|string $value) use (&$headers, $outboundRequest): RequestInterface {
                $headers[$name] = (string) $value;

                return $outboundRequest;
            }
        );
        new HmacRequestService($credentialId->toString(), $hmacSharedSecret)->signRequest($outboundRequest);
        $request = new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1&sort=created_at',
            new DateTimeImmutable('@'.$headers['X-Timestamp']),
            $headers['X-Nonce'],
            $credentialId,
            $headers['Authorization'],
            $headers['Signature'],
            $headers['X-Content-SHA256'],
            $body
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = $this->agentRepository($credentialId, $unitOfWork);
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $this->fightCommonVerifier(),
            new FixedClock($request->getTimestamp()),
            new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork),
            $unitOfWork
        );

        $result = $service->authenticate($request);

        self::assertSame($credentialId, $result->getCredentialId());
        self::assertSame(0, $result->getCredentialRevision());
    }

    public function test_that_it_authenticates_an_active_agent_from_the_fight_common_v1_canonical_fixture(): void
    {
        $agentId = AgentId::fromString('018f0000-0000-7000-8000-000000000001');
        $credentialId = AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002');
        $request = new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1&sort=created_at',
            new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            'nonce-0001',
            $credentialId,
            'HMAC-SHA256',
            '3b7b1edffe123dfa5c5aea516326901792d750572fdd90b97e614c60e705f95f',
            hash('sha256', 'request-body'),
            'request-body'
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $agentRepository->add(Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:0123456789abcdef',
            new DateTimeImmutable('2026-08-26T11:00:00+00:00')
        ));
        $verifier = new FixedHmacSignedAgentRequestVerifier(
            $request,
            '0123456789abcdef'
        );
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork);
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            $nonceConsumer,
            $unitOfWork
        );

        $result = $service->authenticate($request);

        self::assertSame($agentId, $result->getAgentId());
        self::assertSame($credentialId, $result->getCredentialId());
        self::assertSame(0, $result->getCredentialRevision());
        self::assertSame(1, $verifier->calls());
        self::assertSame(1, $nonceConsumer->consumptionCalls());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame('2026-08-26T12:05:00+00:00', $nonceConsumer->expiresAt()?->format(DATE_ATOM));
    }

    public function test_that_it_rejects_an_unknown_credential_without_verifying_a_signature(): void
    {
        $request = $this->request();
        $verifier = new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef');
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork),
            $unitOfWork
        );

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        try {
            $service->authenticate($request);
        } finally {
            self::assertSame(0, $verifier->calls());
        }
    }

    public function test_that_it_rejects_expired_and_future_timestamps_without_verifying_a_signature(): void
    {
        foreach (
            [
                $this->request(new DateTimeImmutable('2026-08-26T11:59:59+00:00')),
                $this->request(new DateTimeImmutable('2026-08-26T12:05:01+00:00')),
            ] as $request
        ) {
            $verifier = new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef');
            $unitOfWork = new InMemoryUnitOfWork();
            $agentRepository = new InMemoryAgentRepository($unitOfWork);
            $service = new AgentAuthenticationService(
                $agentRepository,
                new FixedHmacSharedSecretDecipher('encrypted:'),
                $verifier,
                new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
                new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork),
                $unitOfWork
            );

            try {
                $service->authenticate($request);
                self::fail('Expected the timestamp to reject generically.');
            } catch (AgentAuthenticationRejectedException $exception) {
                self::assertSame('Agent authentication rejected.', $exception->getMessage());
            }

            self::assertSame(0, $verifier->calls());
        }
    }

    public function test_that_it_rejects_bad_body_digests_before_verification(): void
    {
        foreach (
            [
                [
                    $this->request(bodyDigest: null, deriveBodyDigest: false),
                    'an absent digest for a nonempty body',
                ],
                [$this->request(bodyDigest: 'wrong-body-digest'), 'a wrong digest'],
                [$this->request(body: '', bodyDigest: hash('sha256', 'request-body')), 'a digest for an empty body'],
            ] as [$request, $description]
        ) {
            $agentRepository = $this->agentRepository($request->getCredentialId());
            $verifier = new FixedHmacSignedAgentRequestVerifier(
                $this->request(),
                '0123456789abcdef'
            );
            $unitOfWork = new InMemoryUnitOfWork();
            $nonceConsumer = new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork);
            $service = new AgentAuthenticationService(
                $agentRepository,
                new FixedHmacSharedSecretDecipher('encrypted:'),
                $verifier,
                new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
                $nonceConsumer,
                $unitOfWork
            );

            try {
                $service->authenticate($request);
                self::fail(sprintf('Expected %s to reject generically.', $description));
            } catch (AgentAuthenticationRejectedException $exception) {
                self::assertSame('Agent authentication rejected.', $exception->getMessage());
            }

            self::assertSame(0, $verifier->calls());
            self::assertSame(0, $nonceConsumer->consumptionCalls());
        }
    }

    public function test_that_it_rejects_a_wrong_signature_without_a_nonce_operation(): void
    {
        $request = $this->request(signature: 'wrong-signature');
        $agentRepository = $this->agentRepository($request->getCredentialId());
        $verifier = new FixedHmacSignedAgentRequestVerifier($this->request(), '0123456789abcdef');
        $unitOfWork = new InMemoryUnitOfWork();
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork);
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            $nonceConsumer,
            $unitOfWork
        );

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        try {
            $service->authenticate($request);
        } finally {
            self::assertSame(1, $verifier->calls());
            self::assertSame(0, $nonceConsumer->consumptionCalls());
        }
    }

    public function test_that_it_rejects_a_replayed_nonce_after_exactly_one_valid_consumption(): void
    {
        $request = $this->request();
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = $this->agentRepository($request->getCredentialId(), $unitOfWork);
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork);
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef'),
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            $nonceConsumer,
            $unitOfWork
        );

        $service->authenticate($request);

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        try {
            $service->authenticate($request);
        } finally {
            self::assertSame(2, $nonceConsumer->consumptionCalls());
            self::assertSame(2, $unitOfWork->transactions);
        }
    }

    public function test_that_it_rejects_when_the_current_credential_changes_before_atomic_nonce_consumption(): void
    {
        $request = $this->request();
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = $this->agentRepository($request->getCredentialId(), $unitOfWork);
        $agent = $agentRepository->getByCredentialId($request->getCredentialId());
        self::assertInstanceOf(Agent::class, $agent);
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer(
            $agentRepository,
            $unitOfWork,
            function () use ($agentRepository, $agent, $request): void {
                $agentRepository->replace(
                    $agent,
                    $agent->rotateCredential(
                        $request->getCredentialId(),
                        AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000003'),
                        'encrypted:fedcba9876543210',
                        new DateTimeImmutable('2026-08-26T12:04:00+00:00')
                    )
                );
            }
        );
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef'),
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            $nonceConsumer,
            $unitOfWork
        );

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        try {
            $service->authenticate($request);
        } finally {
            self::assertSame(1, $nonceConsumer->consumptionCalls());
            self::assertSame(1, $unitOfWork->transactions);
        }
    }

    public function test_that_it_rejects_when_the_agent_is_revoked_before_atomic_nonce_consumption(): void
    {
        $request = $this->request();
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = $this->agentRepository($request->getCredentialId(), $unitOfWork);
        $agent = $agentRepository->getByCredentialId($request->getCredentialId());
        self::assertInstanceOf(Agent::class, $agent);
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer(
            $agentRepository,
            $unitOfWork,
            function () use ($agentRepository, $agent): void {
                $agentRepository->replace(
                    $agent,
                    $agent->revoke(new DateTimeImmutable('2026-08-26T12:04:00+00:00'))
                );
            }
        );
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef'),
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            $nonceConsumer,
            $unitOfWork
        );

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        try {
            $service->authenticate($request);
        } finally {
            self::assertSame(1, $nonceConsumer->consumptionCalls());
            self::assertSame(1, $unitOfWork->transactions);
        }
    }

    public function test_that_it_rejects_when_permission_assignments_change_before_atomic_nonce_consumption(): void
    {
        $request = $this->request();
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = $this->agentRepository($request->getCredentialId(), $unitOfWork);
        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $permission = Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000003'),
            PermissionName::fromString('VIEW_AGENTS'),
            new DateTimeImmutable('2026-08-26T12:04:00+00:00')
        );
        $permissionRepository->add($permission);
        $agent = $agentRepository->getByCredentialId($request->getCredentialId());
        self::assertInstanceOf(Agent::class, $agent);
        $nonceConsumer = new InMemoryAgentRequestNonceConsumer(
            $agentRepository,
            $unitOfWork,
            function () use ($agentRepository, $agent, $permission): void {
                self::assertTrue($agentRepository->replacePermissionAssignments(
                    $agent,
                    $agent->grantPermission(
                        $permission->getId(),
                        new DateTimeImmutable('2026-08-26T12:04:00+00:00')
                    )
                ));
            }
        );
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef'),
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            $nonceConsumer,
            $unitOfWork
        );

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        try {
            $service->authenticate($request);
        } finally {
            self::assertSame(1, $nonceConsumer->consumptionCalls());
            self::assertSame(1, $unitOfWork->transactions);
        }
    }

    public function test_that_it_rejects_a_transactional_nonce_consumption_failure_generically(): void
    {
        $request = $this->request();
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = $this->agentRepository($request->getCredentialId(), $unitOfWork);
        $service = new AgentAuthenticationService(
            $agentRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, '0123456789abcdef'),
            new FixedClock(new DateTimeImmutable('2026-08-26T12:05:00+00:00')),
            new InMemoryAgentRequestNonceConsumer(
                $agentRepository,
                $unitOfWork,
                failure: new RuntimeException('nonce backend unavailable')
            ),
            $unitOfWork
        );

        $this->expectException(AgentAuthenticationRejectedException::class);
        $this->expectExceptionMessage('Agent authentication rejected.');

        $service->authenticate($request);
    }

    private function agentRepository(
        AgentCredentialId $credentialId,
        ?InMemoryUnitOfWork $unitOfWork = null
    ): InMemoryAgentRepository {
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $agentRepository->add(Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:0123456789abcdef',
            new DateTimeImmutable('2026-08-26T11:00:00+00:00')
        ));

        return $agentRepository;
    }

    private function fightCommonVerifier(): HmacSignedAgentRequestVerifier
    {
        return new FightCommonHmacSignedAgentRequestVerifier(
            function (SignedAgentRequest $signedAgentRequest): ServerRequestInterface {
                $headers = [
                    'Authorization' => $signedAgentRequest->getAuthorizationAlgorithm(),
                    'Credential' => $signedAgentRequest->getCredentialId()->toString(),
                    'Signature' => $signedAgentRequest->getSignature(),
                    'X-Nonce' => $signedAgentRequest->getNonce(),
                    'X-Timestamp' => (string) $signedAgentRequest->getTimestamp()->getTimestamp(),
                ];
                if ($signedAgentRequest->getBodyDigest() !== null) {
                    $headers['X-Content-SHA256'] = $signedAgentRequest->getBodyDigest();
                }

                $uri = $this->createMock(UriInterface::class);
                $uri->method('getAuthority')->willReturn($signedAgentRequest->getAuthority());
                $uri->method('getPath')->willReturn($signedAgentRequest->getPath());
                $uri->method('getQuery')->willReturn($signedAgentRequest->getNormalizedQuery());
                $uri->expects($this->once())
                    ->method('withQuery')
                    ->with($signedAgentRequest->getNormalizedQuery())
                    ->willReturn($uri);
                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($signedAgentRequest->getBody());
                $serverRequest = $this->createMock(ServerRequestInterface::class);
                $serverRequest->method('hasHeader')->willReturnCallback(
                    static fn(string $name): bool => array_key_exists($name, $headers)
                );
                $serverRequest->method('getHeaderLine')->willReturnCallback(
                    static fn(string $name): string => $headers[$name] ?? ''
                );
                $serverRequest->method('getServerParams')->willReturn([
                    'REQUEST_TIME' => $signedAgentRequest->getTimestamp()->getTimestamp(),
                ]);
                $serverRequest->method('getBody')->willReturn($stream);
                $serverRequest->method('getMethod')->willReturn($signedAgentRequest->getMethod());
                $serverRequest->method('getUri')->willReturn($uri);

                return $serverRequest;
            }
        );
    }

    private function request(
        ?DateTimeImmutable $timestamp = null,
        ?string $bodyDigest = null,
        string $signature = '3b7b1edffe123dfa5c5aea516326901792d750572fdd90b97e614c60e705f95f',
        string $body = 'request-body',
        bool $deriveBodyDigest = true
    ): SignedAgentRequest {
        return new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/v1/agents',
            'page=1&sort=created_at',
            $timestamp ?? new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            'nonce-0001',
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002'),
            'HMAC-SHA256',
            $signature,
            $deriveBodyDigest ? ($bodyDigest ?? hash('sha256', $body)) : $bodyDigest,
            $body
        );
    }
}
