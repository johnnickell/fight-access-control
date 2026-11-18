<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\Attribute;

use Attribute;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use InvalidArgumentException;

/**
 * Class FeatureFlag
 *
 * Declares one cumulative class or method reference, not runtime enforcement or authorization.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class FeatureFlag
{
    private FeatureName $name;

    /**
     * Constructs FeatureFlag
     */
    public function __construct(string $name)
    {
        if (func_num_args() !== 1) {
            throw new InvalidArgumentException('A Feature declaration requires exactly one name.');
        }

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
