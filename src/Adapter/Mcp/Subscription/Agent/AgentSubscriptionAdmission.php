<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Subscription\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriptionScope;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionAdmission;
use Fight\Common\Domain\Mcp\Notice\Notice;
use LogicException;

/**
 * Class AgentSubscriptionAdmission
 *
 * Resolves original-bound authority at each Common opening and final notification check, never from protocol IDs.
 * Separate configured authorities keep listening and whole-signal disclosure requirements independent. The check
 * does not lock the subsequent send or retract bytes. Common owns tracking, source Queries and transport lifetime.
 */
final readonly class AgentSubscriptionAdmission implements McpSubscriptionAdmission
{
    /**
     * Constructs AgentSubscriptionAdmission
     */
    public function __construct(
        private string $subscriberReference,
        private AgentNoticeAuthority $openingAuthority,
        private AgentNoticeAuthority $disclosureAuthority,
        private AgentSubscriptionScope $scope,
        private AgentSubscriptionFamilies $families
    ) {
        Notice::reference($subscriberReference);
    }

    /**
     * @inheritDoc
     */
    public function subscriber(): string
    {
        return $this->subscriberReference;
    }

    /**
     * @inheritDoc
     */
    public function allows(string $subscriber, string $filter, ?string $uri, ?Notice $notice): bool
    {
        if (
            $subscriber !== $this->subscriberReference
            || !in_array($filter, ['toolsListChanged', 'resourcesListChanged', 'resourceSubscriptions'], true)
            || (($filter === 'resourceSubscriptions') !== ($uri !== null))
        ) {
            return false;
        }

        // Listening is still required for delivery, but is never sufficient disclosure authority.
        $principal = $this->openingAuthority->resolve();
        if ($principal === null) {
            return false;
        }

        if ($notice !== null) {
            $disclosurePrincipal = $this->disclosureAuthority->resolve();
            if ($disclosurePrincipal === null) {
                return false;
            }

            if (
                !$principal->getAgentId()->equals($disclosurePrincipal->getAgentId())
                || !$principal->getCredentialId()->equals($disclosurePrincipal->getCredentialId())
                || $principal->getCredentialRevision() !== $disclosurePrincipal->getCredentialRevision()
            ) {
                throw new LogicException('Subscription authority binding mismatch.');
            }

            $assignmentRevision = $principal->getPermissionAssignmentRevision();
            if ($assignmentRevision !== $disclosurePrincipal->getPermissionAssignmentRevision()) {
                return false;
            }

            $principal = $disclosurePrincipal;
        }

        return $this->families->allows($principal, $filter, $uri)
            && $this->scope->allows($principal, $filter, $uri, $notice);
    }
}
