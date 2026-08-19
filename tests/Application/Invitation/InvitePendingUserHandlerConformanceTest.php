<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Conformance\Invitation\InvitationConformance;
use Fight\AccessControl\Conformance\Invitation\InvitationConformanceAdapter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InvitePendingUserHandlerConformanceTest extends TestCase
{
    use InvitationConformance;

    protected function invitations(): InvitationConformanceAdapter
    {
        return new NativeInvitationConformanceAdapter();
    }
}
