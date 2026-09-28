<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurrentAgentPrincipalProvider::class)]
final class AgentRotationAuthenticationTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function boundaries(): iterable
    {
        yield 'before atomic consumption' => [false];
        yield 'after consumption before snapshot' => [true];
    }

    #[DataProvider('boundaries')]
    public function test_actual_rotation_invalidates_an_authentication_in_flight_and_only_successor_authenticates(
        bool $after
    ): void {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        // Two controlled transactions model a credential writer interleaved with the nonce boundary.
        $authenticationTransaction = new InMemoryUnitOfWork();
        $rotate = static function () use ($rotation): void {
            self::assertTrue($rotation->service()->rotate($rotation->key, $rotation->request)->isConfirmed());
        };
        $nonce = new InMemoryAgentRequestNonceConsumer(
            $env->agents,
            $authenticationTransaction,
            beforeConsume: $after ? null : $rotate,
            afterConsume: $after ? $rotate : null
        );
        $original = $this->request($rotation->original->getCredentialId(), 'original-nonce');
        try {
            $this->provider($rotation, $original, 'original-test-secret', $nonce, $authenticationTransaction)
                ->resolve($original, 'rotation-race');
            self::fail('An obsolete authority must not resolve a principal.');
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertSame(1, $nonce->consumptionCalls());
            self::assertSame($after, $nonce->expiresAt() !== null);
        }

        $current = $env->agents->all()[0];
        self::assertSame(1, $current->getCredentialRevision());
        $successor = $this->request($current->getCredentialId(), 'successor-nonce');
        $currentNonce = new InMemoryAgentRequestNonceConsumer($env->agents, $authenticationTransaction);
        $principal = $this->provider(
            $rotation,
            $successor,
            'successor-test-secret',
            $currentNonce,
            $authenticationTransaction
        )->resolve($successor, 'successor');
        self::assertSame(1, $principal->toArray()['credential_revision']);
        self::assertSame($current->getCredentialId()->toString(), $principal->toArray()['credential_id']);
        try {
            $this->provider($rotation, $original, 'original-test-secret', $currentNonce, $authenticationTransaction)
                ->resolve($original, 'retired');
            self::fail('The original credential must remain retired.');
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertSame(1, $currentNonce->consumptionCalls());
        }

        try {
            $this->provider($rotation, $successor, 'successor-test-secret', $currentNonce, $authenticationTransaction)
                ->resolve($successor, 'replay');
            self::fail('Rotation must not weaken nonce replay protection.');
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertSame(2, $currentNonce->consumptionCalls());
        }
    }

    private function request(AgentCredentialId $credential, string $nonce): SignedAgentRequest
    {
        return new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/agents',
            '',
            new DateTimeImmutable('2026-09-27T12:00:00Z'),
            $nonce,
            $credential,
            'HMAC-SHA256',
            'valid-signature',
            null,
            ''
        );
    }

    private function provider(
        RotationEnvironment $rotation,
        SignedAgentRequest $request,
        string $secret,
        InMemoryAgentRequestNonceConsumer $nonce,
        InMemoryUnitOfWork $transaction
    ): CurrentAgentPrincipalProvider {
        return new CurrentAgentPrincipalProvider(
            $rotation->provisioning->agents,
            new InMemoryPermissionRepository(),
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($request, $secret),
            new FixedClock(new DateTimeImmutable('2026-09-27T12:00:00Z')),
            $nonce,
            $transaction
        );
    }
}
