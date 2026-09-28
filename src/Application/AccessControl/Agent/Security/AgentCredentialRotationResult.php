<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Class AgentCredentialRotationResult
 *
 * Reports confirmed original issuance or commit uncertainty without credential material.
 */
final readonly class AgentCredentialRotationResult
{
    /**
     * Constructs AgentCredentialRotationResult
     */
    private function __construct(private ?AgentIssuance $issuance, private ?AgentPublicationWarning $warning)
    {
    }

    /**
     * Creates confirmed metadata without asserting delivery, activation or permission to launch
     */
    public static function confirmed(AgentIssuance $issuance, ?AgentPublicationWarning $warning = null): self
    {
        return new self($issuance, $warning);
    }

    /**
     * Creates an indeterminate result requiring authoritative same-key resolution
     */
    public static function indeterminate(): self
    {
        return new self(null, null);
    }

    /**
     * Returns whether the original issuance commit is confirmed
     */
    public function isConfirmed(): bool
    {
        return $this->issuance !== null;
    }

    /**
     * Returns confirmed original metadata without guessing an uncertain outcome
     */
    public function getIssuance(): ?AgentIssuance
    {
        return $this->issuance;
    }

    /**
     * Returns sanitized publication status independently of the committed outcome
     */
    public function getWarning(): ?AgentPublicationWarning
    {
        return $this->warning;
    }
}
