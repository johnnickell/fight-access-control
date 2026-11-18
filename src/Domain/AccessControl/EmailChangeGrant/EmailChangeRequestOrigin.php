<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class EmailChangeRequestOrigin
 *
 * Retains the original requester and canonical old mailbox on the exact grant generation.
 * The mailbox is protected personal data, not an audit or message field.
 */
final readonly class EmailChangeRequestOrigin
{
    public string $oldMailbox;

    /**
     * Constructs EmailChangeRequestOrigin
     */
    public function __construct(public UserId $requesterId, EmailAddress $oldMailbox)
    {
        $this->oldMailbox = $oldMailbox->canonical();
    }
}
