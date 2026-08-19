<?php

declare(strict_types=1);

namespace Fight\AccessControl\Conformance\Invitation;

use Fight\AccessControl\Application\Invitation\DuplicateEmail;
use Fight\AccessControl\Application\Invitation\InvitePendingUser;

/**
 * Reusable, framework-neutral invitation outcomes for consumer test suites.
 */
trait InvitationConformance
{
    /**
     * Proves that invitation success exposes the shared framework-neutral view.
     */
    public function test_a_bound_consumer_observes_the_framework_neutral_invitation_outcome(): void
    {
        $outcome = $this->invitations()->invite(new InvitePendingUser('Admin-42', ' Alice@example.test '));

        self::assertSame('alice@example.test', $outcome->email());
        self::assertSame('pending_activation', $outcome->state());
    }

    /**
     * Proves that canonical email conflicts use the shared failure outcome.
     */
    public function test_a_bound_consumer_rejects_an_already_reserved_canonical_email(): void
    {
        $invitations = $this->invitations();
        $invitations->invite(new InvitePendingUser('Admin-42', 'alice@example.test'));

        $this->expectException(DuplicateEmail::class);
        $invitations->invite(new InvitePendingUser('Admin-42', ' ALICE@example.test '));
    }

    /**
     * Binds this conformance suite to the consumer's configured entry point.
     */
    abstract protected function invitations(): InvitationConformanceAdapter;
}
