<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SignedAgentRequest::class)]
#[CoversClass(AgentAuthenticationRejectedException::class)]
final class SignedAgentRequestTest extends TestCase
{
    public function test_sensitive_signed_request_state_is_not_debugged_or_serialized(): void
    {
        $request = new SignedAgentRequest(
            'POST',
            'fixture.example',
            '/',
            'private-query',
            new DateTimeImmutable('2026-11-08T12:00:00Z'),
            'private-nonce',
            AgentCredentialId::generate(),
            'HMAC-SHA256',
            'private-signature',
            'private-digest',
            'private-body'
        );
        ob_start();
        var_dump($request);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('private-', $debug);
        self::assertSame('{}', json_encode($request, JSON_THROW_ON_ERROR));
        foreach (
            [static fn(): string => serialize($request), static fn(): mixed => unserialize(
                sprintf('O:%d:"%s":0:{}', strlen(SignedAgentRequest::class), SignedAgentRequest::class)
            )] as $operation
        ) {
            try {
                $operation();
                self::fail('Signed requests are not a message or handoff format.');
            } catch (RuntimeException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
    }

    public function test_that_it_preserves_the_portable_canonical_components_and_rejects_malformed_values(): void
    {
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
            'signature-value',
            'body-digest-value',
            'request-body'
        );

        self::assertSame('POST', $request->getMethod());
        self::assertSame('api.fight.example', $request->getAuthority());
        self::assertSame('/v1/agents', $request->getPath());
        self::assertSame('page=1&sort=created_at', $request->getNormalizedQuery());
        self::assertSame('2026-08-26T12:00:00+00:00', $request->getTimestamp()->format(DATE_ATOM));
        self::assertSame('nonce-0001', $request->getNonce());
        self::assertSame($credentialId, $request->getCredentialId());
        self::assertSame('HMAC-SHA256', $request->getAuthorizationAlgorithm());
        self::assertSame('signature-value', $request->getSignature());
        self::assertSame('body-digest-value', $request->getBodyDigest());
        self::assertSame('request-body', $request->getBody());

        try {
            new SignedAgentRequest(
                'post',
                '',
                'agents',
                '?page=1',
                new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
                '',
                $credentialId,
                '',
                '',
                '',
                ''
            );
            self::fail('Expected malformed signed-request values to reject generically.');
        } catch (AgentAuthenticationRejectedException $agentAuthenticationRejectedException) {
            self::assertSame('Agent authentication rejected.', $agentAuthenticationRejectedException->getMessage());
        }
    }
}
