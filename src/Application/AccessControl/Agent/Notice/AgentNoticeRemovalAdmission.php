<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\Common\Application\Mcp\Notice\Exception\NoticeRemovalRejected;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRemovalAdmission;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeRemovalSelection;

/**
 * Class AgentNoticeRemovalAdmission
 */
final readonly class AgentNoticeRemovalAdmission implements NoticeRemovalAdmission
{
    /**
     * Constructs AgentNoticeRemovalAdmission
     */
    public function __construct(private AgentNoticeAuthority $authority, private AgentNoticePolicy $policy)
    {
    }

    /**
     * @inheritDoc
     */
    public function admit(NoticeRemovalSelection $selection): void
    {
        $principal = $this->authority->resolve();
        if ($principal === null || !$this->policy->allowsRemoval($principal, $selection)) {
            throw new NoticeRemovalRejected();
        }
    }

    /**
     * @inheritDoc
     */
    public function admitRetained(Notice $notice): void
    {
        $principal = $this->authority->resolve();
        if ($principal === null || !$this->policy->allowsRetainedRemoval($principal, $notice)) {
            throw new NoticeRemovalRejected();
        }
    }
}
