<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Notice\Data\NoticeView;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationGrant;

/**
 * Interface AgentNoticePublicationScope
 *
 * Consumers own current whole-audience topic, transport mode and field disclosure policy.
 * Mandatory identity fields must also be safe for the selected audience. Recipients are not grants.
 */
interface AgentNoticePublicationScope
{
    /**
     * Returns a fresh complete disclosure grant or null without containing operational faults
     *
     * No read lease or permission stability through hub I/O is implied by a returned grant.
     */
    public function currentGrant(AuthenticatedAgentPrincipal $principal, NoticeView $notice): ?NoticePublicationGrant;
}
