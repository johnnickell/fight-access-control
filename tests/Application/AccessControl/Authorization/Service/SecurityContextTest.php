<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalRole;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversClass(SecurityContext::class)]
final class SecurityContextTest extends TestCase
{
    public function test_it_accepts_exactly_one_typed_authenticated_authority(): void
    {
        $constructor = new ReflectionClass(SecurityContext::class)->getConstructor();

        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters());
        $parameterType = $constructor->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $parameterType);
        self::assertSame(AuthenticatedAuthority::class, $parameterType->getName());
        self::assertFalse($constructor->getParameters()[0]->isVariadic());
    }

    public function test_it_returns_and_delegates_to_the_selected_user_snapshot_for_its_request_lifetime(): void
    {
        $userPrincipal = new AuthenticatedUserPrincipal(
            UserId::generate(),
            RefreshSessionId::generate(),
            3,
            [new PrincipalRole(RoleId::generate(), RoleName::fromString('ROLE_EDITOR'))],
            [new PrincipalPermission(PermissionId::generate(), PermissionName::fromString('PUBLISH_ARTICLE'))]
        );
        $context = new SecurityContext($userPrincipal);

        self::assertSame($userPrincipal, $context->getAuthenticatedAuthority());
        self::assertSame($userPrincipal, $context->getAuthenticatedAuthority());
        self::assertTrue($context->hasPermission(PermissionName::fromString('PUBLISH_ARTICLE')));
        self::assertFalse($context->hasPermission(PermissionName::fromString('DELETE_ARTICLE')));
        self::assertTrue($context->hasRole(RoleName::fromString('ROLE_EDITOR')));
        self::assertFalse($context->hasRole(RoleName::fromString('ROLE_ADMIN')));
    }

    public function test_it_returns_and_delegates_to_the_selected_agent_snapshot_without_role_authority(): void
    {
        $agentPrincipal = new AuthenticatedAgentPrincipal(
            AgentId::generate(),
            AgentCredentialId::generate(),
            2,
            5,
            [new PrincipalPermission(PermissionId::generate(), PermissionName::fromString('READ_AGENT'))]
        );
        $context = new SecurityContext($agentPrincipal);

        self::assertSame($agentPrincipal, $context->getAuthenticatedAuthority());
        self::assertSame($agentPrincipal, $context->getAuthenticatedAuthority());
        self::assertTrue($context->hasPermission(PermissionName::fromString('READ_AGENT')));
        self::assertFalse($context->hasPermission(PermissionName::fromString('WRITE_AGENT')));
        self::assertFalse($context->hasRole(RoleName::fromString('ROLE_AGENT')));
    }
}
