<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Exception;

/**
 * Class RoleConflictException
 *
 * Reports stale Role intent or a lost final persistence fence
 */
final class RoleConflictException extends CustomRoleException
{
    /**
     * Constructs RoleConflictException
     */
    public function __construct()
    {
        parent::__construct('The Role state is no longer current. Refresh and reconfirm the operation.');
    }
}
