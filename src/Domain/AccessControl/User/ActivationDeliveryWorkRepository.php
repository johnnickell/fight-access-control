<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Interface ActivationDeliveryWorkRepository
 */
interface ActivationDeliveryWorkRepository
{
    /**
     * Adds encrypted activation delivery work.
     *
     * @throws Exception When an error occurs
     */
    public function add(ActivationDeliveryWork $work): void;
}
