<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationHandoff;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentContinuationBinding
 *
 * Retains verified provenance locally, without becoming a transport credential or a continuing allow.
 */
final readonly class AgentContinuationBinding
{
    /**
     * Constructs AgentContinuationBinding
     */
    private function __construct(private AgentContinuationContext $context)
    {
    }

    /**
     * Creates a local binding from the same trusted provider used to admit the original request
     */
    public static function fromRequest(
        #[SensitiveParameter] CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] SignedAgentRequest $request,
        string $correlationId
    ): self {
        try {
            $provider->resolve($request, $correlationId);

            return new self($provider->continuationContext());
        } catch (Throwable) {
            throw new AgentContinuationRejectedException();
        }
    }

    /**
     * Creates a worker-local binding only through an explicitly trusted handoff verifier
     */
    public static function fromHandoff(#[SensitiveParameter] AgentContinuationHandoff $handoff): self
    {
        try {
            return new self($handoff->verify());
        } catch (Throwable) {
            throw new AgentContinuationRejectedException();
        }
    }

    /**
     * Returns the original context for trusted handoff and current admission composition
     */
    public function getContext(): AgentContinuationContext
    {
        return $this->context;
    }

    /**
     * Rejects serialization as a credential or worker handoff
     */
    public function __serialize(): array
    {
        throw new AgentContinuationRejectedException();
    }

    /**
     * Rejects hydration without verified provenance
     *
     * @phpstan-param array<mixed> $data
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new AgentContinuationRejectedException();
    }

    /**
     * Returns an empty diagnostic projection
     *
     * @return array<never, never>
     */
    public function __debugInfo(): array
    {
        return [];
    }
}
