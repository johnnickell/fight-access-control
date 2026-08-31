<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\Common\Domain\Type\Arrayable;
use InvalidArgumentException;

/**
 * Captures the server-observable, secret-free outcome of one rejected Agent-principal resolution.
 */
final readonly class AgentAuthenticationDiagnostic implements Arrayable
{
    /**
     * Creates the safe resolution diagnostic.
     */
    public function __construct(
        private AgentAuthenticationDiagnosticClassification $classification,
        private string $correlationId
    ) {
        if (trim($correlationId) === '') {
            throw new InvalidArgumentException(
                'The Agent authentication diagnostic correlation identifier is required.'
            );
        }
    }

    /**
     * Returns the safe failure classification.
     */
    public function getClassification(): AgentAuthenticationDiagnosticClassification
    {
        return $this->classification;
    }

    /**
     * Returns the consumer-owned correlation identifier.
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Returns the exact safe diagnostic representation.
     *
     * @return array{classification: string, correlation_id: string}
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification->value,
            'correlation_id' => $this->correlationId,
        ];
    }
}
