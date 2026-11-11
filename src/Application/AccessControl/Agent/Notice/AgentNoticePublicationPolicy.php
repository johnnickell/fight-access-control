<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\Common\Application\Mcp\Notice\Data\NoticeView;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationGrant;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationPolicy;

/**
 * Class AgentNoticePublicationPolicy
 */
final readonly class AgentNoticePublicationPolicy implements NoticePublicationPolicy
{
    /**
     * Constructs AgentNoticePublicationPolicy
     */
    public function __construct(
        private AgentNoticeAuthority $authority,
        private AgentNoticePublicationScope $scope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function currentGrant(NoticeView $notice): ?NoticePublicationGrant
    {
        $principal = $this->authority->resolve();
        if ($principal === null) {
            return null;
        }

        return $this->scope->currentGrant($principal, $notice);
    }
}
