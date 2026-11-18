<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;
use Fight\Common\Application\Mcp\Notice\Service\NoticeReadAdmission;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use SensitiveParameter;

/**
 * Class AgentNoticeReadAdmission
 *
 * Reuses ordinary request authentication and its snapshot, never a continuation or live-authority lease.
 * A new request must compose a fresh provider; Tool and direct Query paths share this same inward admission.
 */
final readonly class AgentNoticeReadAdmission implements NoticeReadAdmission
{
    private PermissionDeclaration $permissionDeclaration;

    /**
     * Constructs AgentNoticeReadAdmission
     *
     * @phpstan-param array<array-key, mixed> $requirements
     */
    public function __construct(
        private CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private SignedAgentRequest $request,
        private string $correlationId,
        array $requirements,
        private AgentNoticeReadPolicy $policy
    ) {
        $this->permissionDeclaration = new PermissionDeclaration(AdmissionMode::PROTECTED, $requirements);
    }

    /**
     * @inheritDoc
     */
    public function currentAccess(NoticeSelection $selection): NoticeAccess
    {
        $principal = $this->provider->resolve($this->request, $this->correlationId);
        foreach ($this->permissionDeclaration->requirements as $requirement) {
            if (!$principal->hasPermission($requirement)) {
                throw new PermissionDenied('Notice read permission is required.');
            }
        }

        return $this->policy->accessFor($principal);
    }
}
