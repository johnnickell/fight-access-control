<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;

/**
 * Carries the transport-neutral components of one Agent HMAC request.
 */
final readonly class SignedAgentRequest
{
    /**
     * Creates a validated portable signed Agent request.
     */
    public function __construct(
        private string $method,
        private string $authority,
        private string $path,
        private string $normalizedQuery,
        private DateTimeImmutable $timestamp,
        private string $nonce,
        private AgentCredentialId $credentialId,
        private string $authorizationAlgorithm,
        private string $signature,
        private ?string $bodyDigest,
        private string $body
    ) {
        if (
            $method === ''
            || $method !== strtoupper($method)
            || trim($authority) === ''
            || !str_starts_with($path, '/')
            || str_starts_with($normalizedQuery, '?')
            || trim($nonce) === ''
            || trim($authorizationAlgorithm) === ''
            || trim($signature) === ''
            || $bodyDigest === ''
        ) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }
    }

    /**
     * Returns the request authorization algorithm outside the canonical request.
     */
    public function getAuthorizationAlgorithm(): string
    {
        return $this->authorizationAlgorithm;
    }

    /**
     * Returns the supplied body digest, which is absent for an empty body.
     */
    public function getBodyDigest(): ?string
    {
        return $this->bodyDigest;
    }

    /**
     * Returns the unmodified request body used to validate its supplied digest.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Returns the credential identity outside the canonical request.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the canonical request authority.
     */
    public function getAuthority(): string
    {
        return $this->authority;
    }

    /**
     * Returns the uppercase canonical request method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Returns the canonical request nonce.
     */
    public function getNonce(): string
    {
        return $this->nonce;
    }

    /**
     * Returns the normalized canonical request query.
     */
    public function getNormalizedQuery(): string
    {
        return $this->normalizedQuery;
    }

    /**
     * Returns the canonical request path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns the supplied signature outside the canonical request.
     */
    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * Returns the canonical request timestamp.
     */
    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }
}
