<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use InvalidArgumentException;

/**
 * Class PermissionDeclaration
 *
 * Associates one explicit mode and conjunctive requirement set with a trusted target.
 */
final readonly class PermissionDeclaration
{
    /** @var list<PermissionName> */
    public array $requirements;

    /**
     * Constructs PermissionDeclaration
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    public function __construct(public AdmissionMode $mode, array $requirements = [])
    {
        if (!array_is_list($requirements) || ($mode === AdmissionMode::PROTECTED) !== ($requirements !== [])) {
            throw new InvalidArgumentException('Invalid permission declaration.');
        }

        $seen = [];
        $validated = [];
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof PermissionName || isset($seen[$requirement->toString()])) {
                throw new InvalidArgumentException('Invalid permission requirements.');
            }

            $seen[$requirement->toString()] = true;
            $validated[] = $requirement;
        }

        $this->requirements = $validated;
    }
}
