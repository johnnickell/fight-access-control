<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;

/**
 * Interface AgentNoticeReadPolicy
 *
 * Supplies trusted whole-metadata scope independently of caller selection and coarse Tool availability.
 * Recovery identity must bind this principal and the same grant snapshot. Its revision must change on every
 * intervening eligibility change, including revoke/restore and newly eligible older data. Namespace evidence
 * requires explicit disclosure authority; absent historical evidence must not claim continuity.
 * Empty grants are deliberate exclusion; policy, storage and hydration faults must throw, not return empty success.
 */
interface AgentNoticeReadPolicy
{
    /**
     * Returns trusted grants for all metadata and relationships, excluding any notice not wholly disclosable
     */
    public function accessFor(AuthenticatedAgentPrincipal $principal): NoticeAccess;
}
