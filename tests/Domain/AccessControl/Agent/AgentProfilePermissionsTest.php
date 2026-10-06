<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentProfilePermissions::class)]
final class AgentProfilePermissionsTest extends TestCase
{
    public function test_definitions_preserve_consumer_ids_and_declare_admin_safe_permissions_without_grants(): void
    {
        $readId = PermissionId::fromString('018f0000-0000-7000-8000-000000000070');
        $updateId = PermissionId::fromString('018f0000-0000-7000-8000-000000000071');
        $definitions = AgentProfilePermissions::definitions($readId, $updateId);

        self::assertCount(2, $definitions);
        self::assertSame($readId, $definitions[0]->getId());
        self::assertSame($updateId, $definitions[1]->getId());
        self::assertSame('AGENT_PROFILE_READ', AgentProfilePermissions::READ);
        self::assertSame('AGENT_PROFILE_UPDATE', AgentProfilePermissions::UPDATE);
        self::assertSame('AGENT_PROFILE_READ', $definitions[0]->getName()->toString());
        self::assertSame('AGENT_PROFILE_UPDATE', $definitions[1]->getName()->toString());
        self::assertSame(PermissionTier::ADMIN_SAFE, $definitions[0]->getTier());
        self::assertSame(PermissionTier::ADMIN_SAFE, $definitions[1]->getTier());

        $policy = new ManagedPolicy($definitions, [], [$readId, $updateId]);
        self::assertSame([], $policy->getRoles());
        self::assertEquals($policy, ManagedPolicy::fromArray($policy->toArray()));
        self::assertEquals($definitions, AgentProfilePermissions::definitions($readId, $updateId));

        // Different consumers own different fixed identities for the same Permission names.
        $otherReadId = PermissionId::fromString('018f0000-0000-7000-8000-000000000170');
        $otherUpdateId = PermissionId::fromString('018f0000-0000-7000-8000-000000000171');
        $otherDefinitions = AgentProfilePermissions::definitions($otherReadId, $otherUpdateId);
        self::assertSame($otherReadId, $otherDefinitions[0]->getId());
        self::assertSame($otherUpdateId, $otherDefinitions[1]->getId());
    }

    public function test_equal_identity_values_cannot_define_two_permissions(): void
    {
        $this->expectException(ManagedPolicyDefinitionException::class);
        $this->expectExceptionMessage('Agent profile Permissions require distinct stable identities.');

        AgentProfilePermissions::definitions(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000070'),
            PermissionId::fromString('018f0000-0000-7000-8000-000000000070')
        );
    }
}
