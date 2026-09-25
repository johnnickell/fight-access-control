<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;

/**
 * Class CredentialDeliveryAttemptResult
 *
 * Carries a typed provider result and its package-owned safe failure classification.
 */
final readonly class CredentialDeliveryAttemptResult
{
    /**
     * Constructs CredentialDeliveryAttemptResult
     */
    private function __construct(
        private CredentialDeliveryOutcome $outcome,
        private ?CredentialDeliveryFailure $failure
    ) {
    }

    /**
     * Creates a result from one provider-owned typed outcome
     */
    public static function fromOutcome(CredentialDeliveryOutcome $outcome): self
    {
        $failure = match ($outcome) {
            CredentialDeliveryOutcome::DELIVERED => null,
            CredentialDeliveryOutcome::RETRYABLE_FAILURE => CredentialDeliveryFailure::RETRYABLE_PROVIDER,
            CredentialDeliveryOutcome::PERMANENT_FAILURE => CredentialDeliveryFailure::PERMANENT_PROVIDER
        };

        return new self($outcome, $failure);
    }

    /**
     * Creates a safe retry result for an unexpected provider throwable
     */
    public static function unexpectedFailure(): self
    {
        return new self(
            CredentialDeliveryOutcome::RETRYABLE_FAILURE,
            CredentialDeliveryFailure::UNEXPECTED_PROVIDER
        );
    }

    /**
     * Returns the typed outcome
     */
    public function getOutcome(): CredentialDeliveryOutcome
    {
        return $this->outcome;
    }

    /**
     * Returns the package-owned safe failure classification
     */
    public function getFailure(): ?CredentialDeliveryFailure
    {
        return $this->failure;
    }
}
