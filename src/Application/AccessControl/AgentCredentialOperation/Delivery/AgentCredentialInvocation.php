<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use LogicException;
use SensitiveParameter;

/**
 * Class AgentCredentialInvocation
 *
 * Contains original bytes only for one short-lived admitted call to the fixed registered sink.
 */
final readonly class AgentCredentialInvocation
{
    /**
     * Constructs AgentCredentialInvocation
     */
    public function __construct(private AgentIssuance $issuance, #[SensitiveParameter] private string $secret)
    {
    }

    /**
     * Returns the exact immutable sink binding including its global delivery idempotency key
     */
    public function getIssuance(): AgentIssuance
    {
        return $this->issuance;
    }

    /**
     * Returns original bytes only to the protected sink implementation
     */
    public function revealSecret(): string
    {
        return $this->secret;
    }

    /**
     * Returns a redacted diagnostic representation
     *
     * @return array{invocation: '[REDACTED]'}
     */
    public function __debugInfo(): array
    {
        return ['invocation' => '[REDACTED]'];
    }

    /**
     * Prevents invocation serialization into messages or persistence
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Agent credential invocation cannot be serialized.');
    }
}
