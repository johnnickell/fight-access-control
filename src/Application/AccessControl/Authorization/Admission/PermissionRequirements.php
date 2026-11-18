<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Admission;

use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use InvalidArgumentException;

/**
 * Class PermissionRequirements
 *
 * Binds cumulative metadata and an optional trusted route declaration to one selected action.
 */
final readonly class PermissionRequirements
{
    private ?PermissionDeclaration $declaration;

    /**
     * Constructs PermissionRequirements
     */
    public function __construct(private SelectedAction $action, ?PermissionDeclaration $route = null)
    {
        $names = [];
        foreach ($action->getDeclarations() as $declaration) {
            foreach ($declaration->getAttributes(RequiresPermission::class) as $attribute) {
                // Native construction validates every declaration before effective deduplication.
                $name = $attribute->newInstance()->getName();
                $names[$name->toString()] = $name;
            }
        }

        if ($names === []) {
            $this->declaration = $route;

            return;
        }

        if ($route !== null && $route->mode !== AdmissionMode::PROTECTED) {
            throw new InvalidArgumentException('Explicit admission mode conflicts with required Permissions.');
        }

        foreach ($route->requirements ?? [] as $name) {
            $names[$name->toString()] = $name;
        }

        $this->declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, array_values($names));
    }

    /**
     * Returns the effective declaration for the actually selected action
     *
     * Null preserves missing-declaration behavior; it never implies PUBLIC.
     */
    public function getDeclaration(SelectedAction $selectedAction): ?PermissionDeclaration
    {
        $this->action->assertMatches($selectedAction);

        return $this->declaration;
    }
}
