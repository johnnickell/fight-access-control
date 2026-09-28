<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialReceiptLookup;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialSink;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use LogicException;
use SensitiveParameter;

final class ReceiptLookupSink implements AgentCredentialSink, AgentCredentialReceiptLookup
{
    public int $lookups = 0;

    public ?Closure $beforeLookup = null;

    public ?AgentDeliveryReceipt $wrongReceipt = null;

    public function __construct(private readonly DeliveryEnvironment $environment)
    {
    }

    public function lookupReceipt(AgentIssuance $issuance): ?AgentDeliveryReceipt
    {
        if ($this->environment->provisioning->transaction->transactionActive) {
            throw new LogicException('Receipt lookup must be outside a transaction.');
        }

        ++$this->lookups;
        $this->beforeLookup?->__invoke();

        return $this->wrongReceipt ?? $this->environment->sink->receiptFor($issuance);
    }

    public function assertSupported(AgentIssuance $issuance): void
    {
        $this->environment->sink->assertSupported($issuance);
    }

    public function stage(#[SensitiveParameter] AgentCredentialInvocation $invocation): AgentDeliveryReceipt
    {
        return $this->environment->sink->stage($invocation);
    }

    public function verify(AgentDeliveryReceipt $receipt, AgentIssuance $issuance): bool
    {
        return $this->environment->sink->verify($receipt, $issuance);
    }
}
