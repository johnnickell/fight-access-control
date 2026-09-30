<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

use Fight\Common\Domain\Type\Arrayable;

/**
 * Class FeaturePreparationResult
 *
 * Reports configuration readiness, not runtime availability or deployment authorization.
 */
final readonly class FeaturePreparationResult implements Arrayable
{
    /** @var list<FeaturePreparationIssue> */
    private array $issues;

    /**
     * Constructs FeaturePreparationResult
     */
    public function __construct(FeaturePreparationIssue ...$issues)
    {
        $this->issues = $issues;
    }

    /**
     * Returns whether every discovered reference has a valid stored definition and binding
     */
    public function isPrepared(): bool
    {
        return $this->issues === [];
    }

    /**
     * Returns the unusable references in first-reference order
     *
     * @return list<FeaturePreparationIssue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Returns the safe preparation result
     *
     * @return array{prepared: bool, issues: list<array{name: string, problem: string}>}
     */
    public function toArray(): array
    {
        return [
            'prepared' => $this->isPrepared(),
            'issues'   => array_map(
                static fn(FeaturePreparationIssue $issue): array => $issue->toArray(),
                $this->issues
            )
        ];
    }
}
