<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;

/**
 * Class FeatureReferences
 *
 * Holds validated name-only references without claiming discovery completeness.
 */
final readonly class FeatureReferences
{
    /** @var list<FeatureName> */
    private array $names;

    /**
     * Constructs FeatureReferences
     */
    public function __construct(FeatureName ...$names)
    {
        $unique = [];
        foreach ($names as $name) {
            $unique[$name->toString()] = $name;
        }

        $this->names = array_values($unique);
    }

    /**
     * Creates references by validating every supplied name
     */
    public static function fromStrings(string ...$names): self
    {
        return new self(...array_map(FeatureName::fromString(...), $names));
    }

    /**
     * Returns distinct names in first-reference order
     *
     * @return list<FeatureName>
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * Returns the combined references without changing either input
     */
    public function merge(self $references): self
    {
        return new self(...$this->names, ...$references->names);
    }
}
