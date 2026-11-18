<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Execution;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Session\Service\ExecutionAccess;
use Fight\Common\Application\Mcp\Session\Service\ExecutionReadAdmission;
use Fight\Common\Domain\Mcp\Session\ExecutionSelection;
use SensitiveParameter;

/**
 * Class AgentExecutionReadAdmission
 *
 * Intersects ordinary-request direct Permissions with consumer scope without manufacturing data grants.
 * Share the provider with Tool admission; construct fresh request composition for each later page/request.
 */
final readonly class AgentExecutionReadAdmission implements ExecutionReadAdmission
{
    private PermissionDeclaration $readDeclaration;

    private PermissionDeclaration $referenceDeclaration;

    /**
     * Constructs AgentExecutionReadAdmission
     *
     * Both declarations require nonempty unique canonical PermissionName values from trusted configuration.
     *
     * @phpstan-param array<array-key, mixed> $readRequirements
     * @phpstan-param array<array-key, mixed> $referenceRequirements
     */
    public function __construct(
        private CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private SignedAgentRequest $request,
        private string $correlationId,
        private AgentExecutionScope $scope,
        array $readRequirements,
        array $referenceRequirements
    ) {
        $this->readDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $readRequirements);
        $this->referenceDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $referenceRequirements);
    }

    /**
     * @inheritDoc
     */
    public function currentAccess(ExecutionSelection $selection): ExecutionAccess
    {
        $principal = $this->provider->resolve($this->request, $this->correlationId);
        if (
            !array_all(
                $this->readDeclaration->requirements,
                static fn(PermissionName $permission): bool => $principal->hasPermission($permission)
            )
        ) {
            return ExecutionAccess::create([]);
        }

        $access = $this->scope->currentAccess($principal, $selection);
        if (
            array_all(
                $this->referenceDeclaration->requirements,
                static fn(PermissionName $permission): bool => $principal->hasPermission($permission)
            )
        ) {
            return $access;
        }

        return ExecutionAccess::create($access->projects(), $access->phaseGrants(), $access->activityGrants());
    }
}
