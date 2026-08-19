<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\ActivationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Throwable;

final class RecordingActivationDeliveryInvoker implements ActivationDeliveryInvoker
{
    /** @var list<ActivationDeliveryWork> */
    private array $invokedWork = [];

    /**
     * Creates a deterministic delivery invoker.
     */
    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function invoke(ActivationDeliveryWork $work): void
    {
        $this->invokedWork[] = $work;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    /**
     * Returns every work item passed to the consumer-owned invoker.
     *
     * @return list<ActivationDeliveryWork>
     */
    public function invokedWork(): array
    {
        return $this->invokedWork;
    }
}
