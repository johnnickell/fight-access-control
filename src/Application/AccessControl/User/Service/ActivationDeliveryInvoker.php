<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;

/**
 * Invokes consumer-owned activation delivery without selecting a transport or execution mode.
 */
interface ActivationDeliveryInvoker
{
    /**
     * Invokes one durable activation-delivery work item.
     */
    public function invoke(ActivationDeliveryWork $work): void;
}
