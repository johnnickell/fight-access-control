<?php

declare(strict_types=1);

namespace Fight\AccessControl\Conformance\Invitation;

use Fight\AccessControl\Application\Invitation\InvitationView;
use Fight\AccessControl\Application\Invitation\InvitePendingUser;

/**
 * Binds a consumer invitation entry point to the reusable conformance suite.
 */
interface InvitationConformanceAdapter
{
    /**
     * Invites a pending user through the consumer's configured entry point.
     */
    public function invite(InvitePendingUser $command): InvitationView;
}
