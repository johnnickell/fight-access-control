<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDelivery;

/**
 * Invokes consumer-owned email-change delivery without selecting a transport or execution mode.
 */
interface EmailChangeDeliveryInvoker
{
    /**
     * Invokes one bounded encrypted delivery work item.
     */
    public function invoke(EmailChangeDelivery $work): void;
}
