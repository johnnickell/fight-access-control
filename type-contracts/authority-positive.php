<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\AgentRequestNonceConsumer;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\HmacSharedSecretDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\HmacSignedAgentRequestVerifier;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\AuthenticationContextProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\CurrentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

/**
 * Proves exact authority and bounded callback contracts
 */
function authorityContracts(
    CurrentPrincipalProvider $user,
    AuthenticationContextProvider $claims,
    CurrentAgentPrincipalProvider $agent,
    SignedAgentRequest $request,
    ExactPermissionResolver $permissions,
    AgentContinuationBinding $binding,
    AgentContinuationContext $context,
    AgentContinuationHandoff $handoff,
    AgentContinuationGuard $guard,
    AgentContinuationScope $scope,
    AgentRequestNonceConsumer $nonces,
    HmacSignedAgentRequestVerifier $verifier,
    HmacSharedSecretDecipher $decipher,
    PermissionId $permissionId
): void {
    \PHPStan\dumpType($user->getCurrentPrincipal());
    \PHPStan\dumpType($claims->getAuthenticationContext());
    \PHPStan\dumpType($agent->resolve($request, 'safe-correlation'));
    \PHPStan\dumpType($permissions->resolve([$permissionId]));
    \PHPStan\dumpType($permissions->resolveDefinitions([$permissionId]));
    \PHPStan\dumpType(RequestAuthority::agent($agent, $request, 'safe-correlation')->resolve());
    \PHPStan\dumpType(new PermissionAdmission()->admit(
        new PermissionDeclaration(AdmissionMode::AUTHENTICATED),
        RequestAuthority::user($user)
    ));
    $declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('READ_ITEM')]);
    \PHPStan\dumpType($declaration->requirements);
    \PHPStan\dumpType($agent->continuationContext());
    \PHPStan\dumpType($binding->getContext());
    \PHPStan\dumpType($handoff->verify());
    \PHPStan\dumpType($context->isValidAt($request->getTimestamp()));
    \PHPStan\dumpType($nonces->consume(
        $context->getAgentId(),
        $context->getCredentialId(),
        $context->getCredentialRevision(),
        1,
        $request->getNonce(),
        $request->getTimestamp()
    ));
    \PHPStan\dumpType($verifier->verifies($request, $decipher->decrypt('fixture envelope')));
    \PHPStan\dumpType($guard->run(...));
    \PHPStan\dumpType($scope->isAllowed($agent->resolve($request, 'safe-correlation')));
}
