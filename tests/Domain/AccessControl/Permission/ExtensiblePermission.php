<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;

final class ExtensiblePermission extends Permission
{
    public static function reconstruct(PermissionTier $tier, bool $managed): self
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new self(
            PermissionId::generate(),
            PermissionName::fromString('VIEW_USERS'),
            $tier,
            $managed,
            $createdAt,
            $createdAt
        );
    }
}
