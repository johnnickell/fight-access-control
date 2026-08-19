<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\InvitationDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Throwable;

final class RecordingInvitationDeliveryInvoker implements InvitationDeliveryInvoker
{
    /** @var list<InvitationDelivery> */
    private array $invokedWork = [];

    /**
     * Creates a deterministic delivery invoker.
     */
    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function invoke(InvitationDelivery $work): void
    {
        $this->invokedWork[] = $work;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    /**
     * Returns every work item passed to the consumer-owned invoker.
     *
     * @return list<InvitationDelivery>
     */
    public function invokedWork(): array
    {
        return $this->invokedWork;
    }
}
