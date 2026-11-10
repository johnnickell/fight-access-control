<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Detail;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Detail\Service\DetailAccess;
use Fight\Common\Application\Mcp\Detail\Service\DetailReadAdmission;
use Fight\Common\Domain\Mcp\Detail\DetailSelection;
use SensitiveParameter;

/**
 * Class AgentDetailReadAdmission
 *
 * Intersects ordinary-request direct Permissions with independent consumer scope, without timeline authority.
 * Construct a fresh provider and admission for every later request/page/chunk; share the provider with Tool gates.
 */
final readonly class AgentDetailReadAdmission implements DetailReadAdmission
{
    private PermissionDeclaration $metadataDeclaration;

    private PermissionDeclaration $bodyDeclaration;

    /**
     * Constructs AgentDetailReadAdmission
     *
     * Both sets require nonempty, unique canonical PermissionName values, never caller-supplied strings.
     *
     * @phpstan-param array<array-key, mixed> $metadataRequirements
     * @phpstan-param array<array-key, mixed> $bodyRequirements
     */
    public function __construct(
        private CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private SignedAgentRequest $request,
        private string $correlationId,
        private AgentDetailScope $scope,
        array $metadataRequirements,
        array $bodyRequirements
    ) {
        $this->metadataDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $metadataRequirements);
        $this->bodyDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $bodyRequirements);
    }

    /**
     * @inheritDoc
     */
    public function currentAccess(DetailSelection $selection): DetailAccess
    {
        $principal = $this->provider->resolve($this->request, $this->correlationId);
        if (
            !array_all(
                $this->metadataDeclaration->requirements,
                static fn(PermissionName $permission): bool => $principal->hasPermission($permission)
            )
        ) {
            return DetailAccess::create([]);
        }

        $access = $this->scope->currentAccess($principal, $selection);
        if (
            array_all(
                $this->bodyDeclaration->requirements,
                static fn(PermissionName $permission): bool => $principal->hasPermission($permission)
            )
        ) {
            return $access;
        }

        return DetailAccess::create(
            $access->scope()->projects(),
            $access->scope()->phaseGrants(),
            $access->scope()->detailGrants()
        );
    }
}
