<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use LogicException;
use SensitiveParameter;

/**
 * Class AgentNoticeAuthority
 *
 * Resolves original bound authority afresh at the owning admission point, not a continuing lease.
 */
final readonly class AgentNoticeAuthority
{
    private PermissionDeclaration $permissionDeclaration;

    /**
     * Constructs AgentNoticeAuthority
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    public function __construct(
        #[SensitiveParameter] private AgentContinuationBinding $binding,
        private AgentRepository $agentRepository,
        private PermissionRepository $permissionRepository,
        private Clock $clock,
        array $requirements
    ) {
        $this->permissionDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $requirements);
    }

    /**
     * Returns fresh independent operation authority or known refusal without containing operational faults
     */
    public function resolve(): ?AuthenticatedAgentPrincipal
    {
        $context = $this->binding->getContext();
        $this->agentRepository->getOperationContract()->assertCompatible();
        $agent = $this->agentRepository->getById($context->getAgentId());
        if ($agent === null) {
            return null;
        }

        if (!$agent->getId()->equals($context->getAgentId())) {
            throw new LogicException('Invalid notice authority snapshot.');
        }

        if (!$agent->hasActiveCredential($context->getCredentialId(), $context->getCredentialRevision())) {
            return null;
        }

        $definitions = new ExactPermissionResolver($this->permissionRepository)->resolveDefinitions(
            $agent->getPermissionIds()
        );
        $permissions = [];
        foreach ($definitions as $definition) {
            if ($definition->getTier() !== PermissionTier::ADMIN_SAFE) {
                throw new LogicException('Invalid notice authority snapshot.');
            }

            $permissions[] = new PrincipalPermission($definition->getId(), $definition->getName());
        }

        $principal = new AuthenticatedAgentPrincipal(
            $agent->getId(),
            $agent->getCredentialId(),
            $agent->getCredentialRevision(),
            $agent->getPermissionAssignmentRevision(),
            $permissions
        );
        foreach ($this->permissionDeclaration->requirements as $requirement) {
            if (!$principal->hasPermission($requirement)) {
                return null;
            }
        }

        if (!$context->isValidAt($this->clock->now())) {
            return null;
        }

        return $principal;
    }
}
