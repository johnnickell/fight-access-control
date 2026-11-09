<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature;

use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;

/**
 * Class FeatureRequirements
 *
 * Binds cumulative declaration and trusted route requirements to one selected action, without evaluation.
 */
final readonly class FeatureRequirements
{
    private FeatureReferences $references;

    /**
     * Constructs FeatureRequirements
     */
    public function __construct(private SelectedAction $action, string ...$registeredNames)
    {
        $names = [];
        foreach ($action->getDeclarations() as $declaration) {
            foreach ($declaration->getAttributes(FeatureFlag::class) as $attribute) {
                // Native construction validates placement, repetition and arguments before deduplication.
                $names[] = $attribute->newInstance()->getName();
            }
        }

        $this->references = new FeatureReferences(...$names)->merge(
            FeatureReferences::fromStrings(...$registeredNames)
        );
    }

    /**
     * Returns validated effective references for the actually selected action
     *
     * Empty means no Feature restriction, never public Permission access or authorization.
     */
    public function getReferences(SelectedAction $selectedAction): FeatureReferences
    {
        $this->action->assertMatches($selectedAction);

        return $this->references;
    }
}
