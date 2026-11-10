<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\Common\Application\Mcp\Notice\Exception\NoticeRejected;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAdmission;
use Fight\Common\Domain\Mcp\Notice\Notice;

/**
 * Class AgentNoticeAdmission
 */
final readonly class AgentNoticeAdmission implements NoticeAdmission
{
    /**
     * Constructs AgentNoticeAdmission
     */
    public function __construct(private AgentNoticeAuthority $authority, private AgentNoticePolicy $policy)
    {
    }

    /**
     * @inheritDoc
     */
    public function admit(Notice $notice): void
    {
        $principal = $this->authority->resolve();
        if ($principal === null || !$this->policy->allowsRecording($principal, $notice)) {
            throw new NoticeRejected();
        }
    }
}
