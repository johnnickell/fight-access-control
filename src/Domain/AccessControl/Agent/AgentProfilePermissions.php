<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;

/**
 * Class AgentProfilePermissions
 *
 * Declares managed profile Permissions using stable consumer-owned identities without assigning them.
 */
final class AgentProfilePermissions
{
    public const string READ = 'AGENT_PROFILE_READ';

    public const string UPDATE = 'AGENT_PROFILE_UPDATE';

    /**
     * Creates the two managed definitions for inclusion in the consumer's complete policy
     *
     * @return list<ManagedPermissionDefinition>
     */
    public static function definitions(PermissionId $readId, PermissionId $updateId): array
    {
        if ($readId->equals($updateId)) {
            throw new ManagedPolicyDefinitionException('Agent profile Permissions require distinct stable identities.');
        }

        return [
            new ManagedPermissionDefinition(
                $readId,
                PermissionName::fromString(self::READ),
                PermissionTier::ADMIN_SAFE
            ),
            new ManagedPermissionDefinition(
                $updateId,
                PermissionName::fromString(self::UPDATE),
                PermissionTier::ADMIN_SAFE
            )
        ];
    }
}
