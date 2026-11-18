<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeRemovalSelection;

/**
 * Interface AgentNoticePolicy
 *
 * Supplies current consumer producer, scope, content, reference and retention policy independently per operation.
 * False means deliberate refusal; faults must throw to the single configured safe-error reporting boundary.
 * Submitted topic, recipient, author, subject and causation references never authenticate the producer.
 */
interface AgentNoticePolicy
{
    /**
     * Determines whether every recording input and relationship is currently permitted
     */
    public function allowsRecording(AuthenticatedAgentPrincipal $principal, Notice $notice): bool;

    /**
     * Determines whether every exact selected incarnation is currently permitted for removal before lookup
     */
    public function allowsRemoval(AuthenticatedAgentPrincipal $principal, NoticeRemovalSelection $selection): bool;

    /**
     * Determines whether all retained content and relationships are currently permitted for removal
     */
    public function allowsRetainedRemoval(AuthenticatedAgentPrincipal $principal, Notice $notice): bool;
}
