<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;

/**
 * Rejects incompatible authority identities and copied provenance
 */
function incompatibleAuthorityContracts(
    ExactPermissionResolver $permissions,
    RoleId $wrongIdentity,
    CurrentAgentPrincipalProvider $provider,
    AuthenticatedAgentPrincipal $copiedPrincipal,
    AgentContinuationContext $claims,
    AgentContinuationGuard $guard,
    AgentContinuationScope $scope
): void {
    $permissions->resolve([$wrongIdentity]);
    RequestAuthority::user($provider);
    RequestAuthority::agent($provider, $copiedPrincipal, 'safe-correlation');
    $guard->run($claims, [], $scope, static function (): void {
    });
}
