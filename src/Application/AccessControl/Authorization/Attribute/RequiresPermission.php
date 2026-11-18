<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Attribute;

use Attribute;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use InvalidArgumentException;

/**
 * Class RequiresPermission
 *
 * Declares one cumulative Permission requirement, not authorization or runtime enforcement.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RequiresPermission
{
    private PermissionName $name;

    /**
     * Constructs RequiresPermission
     */
    public function __construct(string $name)
    {
        if (func_num_args() !== 1) {
            throw new InvalidArgumentException('A Permission declaration requires exactly one name.');
        }

        $this->name = PermissionName::fromString($name);
    }

    /**
     * Returns the declared Permission name
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }
}
