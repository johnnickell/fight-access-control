<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Mcp\Subscription\Service\SubscriberAccess;
use Fight\Common\Application\Mcp\Subscription\Service\SubscriberReadAdmission;
use Fight\Common\Domain\Mcp\Subscription\SubscriberSelection;
use SensitiveParameter;

/**
 * Class AgentSubscriberReadAdmission
 *
 * Keeps ordinary request snapshots and independent complete-metadata inspection grants.
 */
final readonly class AgentSubscriberReadAdmission implements SubscriberReadAdmission
{
    private PermissionDeclaration $permissionDeclaration;

    /**
     * Constructs AgentSubscriberReadAdmission
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    public function __construct(
        private CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private SignedAgentRequest $request,
        private string $correlationId,
        array $requirements,
        private AgentSubscriberReadPolicy $policy
    ) {
        $this->permissionDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $requirements);
    }

    /**
     * @inheritDoc
     */
    public function currentAccess(SubscriberSelection $selection): SubscriberAccess
    {
        $principal = $this->provider->resolve($this->request, $this->correlationId);
        foreach ($this->permissionDeclaration->requirements as $requirement) {
            if (!$principal->hasPermission($requirement)) {
                throw new PermissionDenied('Subscriber inspection permission is required.');
            }
        }

        return $this->policy->accessFor($principal);
    }
}
