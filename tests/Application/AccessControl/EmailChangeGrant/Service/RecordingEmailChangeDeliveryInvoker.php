<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeDeliveryInvoker;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDelivery;
use Throwable;

final class RecordingEmailChangeDeliveryInvoker implements EmailChangeDeliveryInvoker
{
    /** @var list<EmailChangeDelivery> */
    private array $invokedWork = [];

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function invoke(EmailChangeDelivery $work): void
    {
        $this->invokedWork[] = $work;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    /** @return list<EmailChangeDelivery> */
    public function invokedWork(): array
    {
        return $this->invokedWork;
    }
}
