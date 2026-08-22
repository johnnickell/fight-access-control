<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Throwable;

final class RecordingInvitationDeliveryInvoker implements InvitationDeliveryInvoker
{
    /** @var list<ActivationDelivery> */
    private array $invokedWork = [];

    /**
     * Creates a deterministic delivery invoker.
     */
    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function invoke(ActivationDelivery $work): void
    {
        $this->invokedWork[] = $work;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    /**
     * Returns every work item passed to the consumer-owned invoker.
     *
     * @return list<ActivationDelivery>
     */
    public function invokedWork(): array
    {
        return $this->invokedWork;
    }
}
