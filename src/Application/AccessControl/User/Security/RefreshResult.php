<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Security;

/**
 * Returns either fresh authentication material or a secretless bounded conflict.
 */
final readonly class RefreshResult
{
    /**
     * Constructs one typed refresh outcome.
     */
    private function __construct(
        private RefreshOutcome $outcome,
        private ?TokenSet $tokenSet
    ) {
    }

    /**
     * Creates a successful rotation result containing fresh secret material.
     */
    public static function rotated(TokenSet $tokenSet): self
    {
        return new self(RefreshOutcome::ROTATED, $tokenSet);
    }

    /**
     * Creates a bounded conflict result containing no secret material.
     */
    public static function conflict(): self
    {
        return new self(RefreshOutcome::CONFLICT, null);
    }

    /**
     * Returns the explicit refresh outcome.
     */
    public function getOutcome(): RefreshOutcome
    {
        return $this->outcome;
    }

    /**
     * Returns fresh authentication material only for a rotation winner.
     */
    public function getTokenSet(): ?TokenSet
    {
        return $this->tokenSet;
    }
}
