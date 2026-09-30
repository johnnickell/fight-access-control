<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\Attribute;

use Attribute;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;

/**
 * Class RequiresFeature
 *
 * Declares one method reference, not runtime enforcement or authorization.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequiresFeature
{
    private FeatureName $name;

    /**
     * Constructs RequiresFeature
     */
    public function __construct(string $name)
    {
        $this->name = FeatureName::fromString($name);
    }

    /**
     * Returns the declared Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }
}
