<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class FeaturePreparationIssue
 *
 * Describes an unusable reference without exposing stored definitions or infrastructure errors.
 */
final readonly class FeaturePreparationIssue implements Arrayable
{
    /**
     * Constructs FeaturePreparationIssue
     */
    public function __construct(private FeatureName $name, private FeaturePreparationProblem $problem)
    {
    }

    /**
     * Returns the affected reference name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }

    /**
     * Returns the configuration failure classification
     */
    public function getProblem(): FeaturePreparationProblem
    {
        return $this->problem;
    }

    /**
     * Returns the safe diagnostic fields
     *
     * @return array{name: string, problem: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name->toString(), 'problem' => $this->problem->value];
    }
}
