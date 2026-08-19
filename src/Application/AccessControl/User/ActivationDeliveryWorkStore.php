<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

/**
 * Persists encrypted delivery work.
 */
interface ActivationDeliveryWorkStore
{
    /**
     * Stages encrypted delivery work for durable persistence.
     */
    public function save(ActivationDeliveryWork $work): void;
}
