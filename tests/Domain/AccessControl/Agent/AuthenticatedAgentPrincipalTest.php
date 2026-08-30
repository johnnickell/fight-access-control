<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AuthenticatedAgentPrincipalException;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticatedAgentPrincipal::class)]
#[CoversClass(AuthenticatedAgentPrincipalException::class)]
#[CoversClass(PrincipalPermission::class)]
final class AuthenticatedAgentPrincipalTest extends TestCase
{
    public function test_it_preserves_a_complete_ordered_secret_free_agent_authority_snapshot(): void
    {
        $readPermission = new PrincipalPermission(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000031'),
            PermissionName::fromString('READ_AGENT')
        );
        $writePermission = new PrincipalPermission(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000032'),
            PermissionName::fromString('WRITE_AGENT')
        );
        $principal = new AuthenticatedAgentPrincipal(
            AgentId::fromString('018f0000-0000-7000-8000-000000000021'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000022'),
            4,
            7,
            [
                $writePermission,
                new PrincipalPermission(
                    PermissionId::fromString($writePermission->getPermissionId()->toString()),
                    $writePermission->getName()
                ),
                $readPermission,
            ]
        );

        $permissions = $principal->getPermissions();
        $permissions = [];

        self::assertSame('018f0000-0000-7000-8000-000000000021', $principal->getAgentId()->toString());
        self::assertSame('018f0000-0000-7000-8000-000000000022', $principal->getCredentialId()->toString());
        self::assertSame(4, $principal->getCredentialRevision());
        self::assertSame(7, $principal->getPermissionAssignmentRevision());
        self::assertSame([$writePermission, $readPermission], $principal->getPermissions());
        self::assertInstanceOf(AuthenticatedAuthority::class, $principal);
        self::assertSame(AuthenticatedPrincipalType::AGENT, $principal->getType());
        self::assertSame(
            '018f0000-0000-7000-8000-000000000032',
            $principal->getPermissions()[0]->getPermissionId()->toString()
        );
        self::assertSame('WRITE_AGENT', $principal->getPermissions()[0]->getName()->toString());
        self::assertTrue($principal->hasPermission(PermissionName::fromString('READ_AGENT')));
        self::assertFalse($principal->hasPermission(PermissionName::fromString('DELETE_AGENT')));
        self::assertFalse($principal->hasRole(RoleName::fromString('ROLE_AGENT')));
        self::assertSame(
            [
                'agent_id' => '018f0000-0000-7000-8000-000000000021',
                'credential_id' => '018f0000-0000-7000-8000-000000000022',
                'credential_revision' => 4,
                'permission_assignment_revision' => 7,
                'permissions' => [
                    [
                        'permission_id' => '018f0000-0000-7000-8000-000000000032',
                        'name' => 'WRITE_AGENT',
                    ],
                    [
                        'permission_id' => '018f0000-0000-7000-8000-000000000031',
                        'name' => 'READ_AGENT',
                    ],
                ],
            ],
            $principal->toArray()
        );
    }

    public function test_it_rejects_a_negative_credential_revision(): void
    {
        $this->expectException(AuthenticatedAgentPrincipalException::class);

        new AuthenticatedAgentPrincipal(
            AgentId::fromString('018f0000-0000-7000-8000-000000000021'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000022'),
            -1,
            1,
            []
        );
    }

    public function test_it_rejects_a_non_positive_permission_assignment_revision(): void
    {
        $this->expectException(AuthenticatedAgentPrincipalException::class);

        new AuthenticatedAgentPrincipal(
            AgentId::fromString('018f0000-0000-7000-8000-000000000021'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000022'),
            0,
            0,
            []
        );
    }

    public function test_it_rejects_an_untyped_permission_snapshot(): void
    {
        $this->expectException(AuthenticatedAgentPrincipalException::class);

        new AuthenticatedAgentPrincipal(
            AgentId::fromString('018f0000-0000-7000-8000-000000000021'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000022'),
            0,
            1,
            ['READ_AGENT']
        );
    }

    public function test_the_obsolete_agent_principal_permission_type_is_not_available(): void
    {
        self::assertFalse(class_exists(
            'Fight\\AccessControl\\Domain\\AccessControl\\Agent\\AgentPrincipalPermission'
        ));
    }
}
