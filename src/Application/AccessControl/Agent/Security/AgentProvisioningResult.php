<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Class AgentProvisioningResult
 *
 * Distinguishes confirmed original issuance from uncertain commit without returning credential material.
 */
final readonly class AgentProvisioningResult
{
    /**
     * Constructs AgentProvisioningResult
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
     * Returns whether commit was confirmed rather than inferred from events or exceptions
     */
    public function isConfirmed(): bool
    {
        return $this->issuance !== null;
    }

    /**
     * Returns confirmed original metadata or no guessed identity when commit is uncertain
     */
    public function getIssuance(): ?AgentIssuance
    {
        return $this->issuance;
    }

    /**
     * Returns sanitized publication status independently of the confirmed issuance outcome
     */
    public function getWarning(): ?AgentPublicationWarning
    {
        return $this->warning;
    }
}
