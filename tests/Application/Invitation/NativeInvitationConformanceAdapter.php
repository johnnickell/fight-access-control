<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Application\Invitation\InvitationView;
use Fight\AccessControl\Application\Invitation\InvitePendingUser;
use Fight\AccessControl\Application\Invitation\InvitePendingUserHandler;
use Fight\AccessControl\Conformance\Invitation\InvitationConformanceAdapter;

/**
 * Binds the native Application handler to the invitation conformance contract.
 */
final readonly class NativeInvitationConformanceAdapter implements InvitationConformanceAdapter
{
    private InvitePendingUserHandler $handler;

    public function __construct()
    {
        $this->handler = new InvitePendingUserHandler(
            new InMemoryUserStore(),
            new InMemoryActivationGrantStore(),
            new InMemoryActivationDeliveryWorkStore(),
            new InMemoryAuditEvidenceStore(),
            new InMemoryUnitOfWork(),
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            new FixedInvitationClock(
                '2026-08-18T12:00:00+00:00',
                '2026-08-18T12:00:00+00:00'
            )
        );
    }

    public function invite(InvitePendingUser $command): InvitationView
    {
        return ($this->handler)($command);
    }
}
