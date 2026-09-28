<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialSink;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use SensitiveParameter;

final class InMemoryAgentCredentialSink implements AgentCredentialSink, AgentCredentialCleanup
{
    public int $calls = 0;

    public int $verifications = 0;

    public int $cleanups = 0;

    public ?Closure $afterCleanup = null;

    public bool $supported = true;

    public bool $validReceipt = true;

    public ?Closure $beforeStage = null;

    public ?Closure $afterStage = null;

    public ?Closure $beforeVerify = null;

    public ?Closure $afterSupported = null;

    /** @var array<string, int> */
    public array $highWater = [];

    /** @var array<string, AgentIssuance> */
    private array $cleanedBindings = [];

    /** @var array<string, array{AgentCredentialInvocation, AgentDeliveryReceipt}> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $removed = [];

    public function __construct(private readonly InMemoryUnitOfWork $transaction)
    {
    }

    public function assertSupported(AgentIssuance $issuance): void
    {
        $this->outsideTransaction();
        if (!$this->supported) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::UNSUPPORTED_SINK);
        }

        $this->afterSupported?->__invoke();
    }

    public function stage(#[SensitiveParameter] AgentCredentialInvocation $invocation): AgentDeliveryReceipt
    {
        $this->outsideTransaction();
        ++$this->calls;
        $this->beforeStage?->__invoke();
        $issuance = $invocation->getIssuance();
        $id = $issuance->getDeliveryId()->toString();
        if (isset($this->removed[$id])) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
        }

        if (isset($this->entries[$id])) {
            [$original, $receipt] = $this->entries[$id];
            if (
                $original->getIssuance()->toArray() !== $issuance->toArray()
                || $original->revealSecret() !== $invocation->revealSecret()
            ) {
                throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
            }

            return $receipt;
        }

        $slot = $issuance->getDestination()->getId()->toString();
        if ($issuance->getDestinationWriteVersion() <= ($this->highWater[$slot] ?? 0)) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
        }

        $receipt = new AgentDeliveryReceipt(bin2hex(random_bytes(16)));
        $this->entries[$id] = [$invocation, $receipt];
        $this->highWater[$slot] = $issuance->getDestinationWriteVersion();
        $this->afterStage?->__invoke();

        return $receipt;
    }

    public function verify(AgentDeliveryReceipt $receipt, AgentIssuance $issuance): bool
    {
        $this->outsideTransaction();
        ++$this->verifications;
        $this->beforeVerify?->__invoke();
        $entry = $this->entries[$issuance->getDeliveryId()->toString()] ?? null;

        return $this->validReceipt && $entry !== null
            && !isset($this->removed[$issuance->getDeliveryId()->toString()])
            && $entry[0]->getIssuance()->toArray() === $issuance->toArray()
            && $entry[1]->toString() === $receipt->toString();
    }

    public function receiptFor(AgentIssuance $issuance): ?AgentDeliveryReceipt
    {
        return ($this->entries[$issuance->getDeliveryId()->toString()] ?? null)[1] ?? null;
    }

    public function stagedBytes(AgentIssuance $issuance): ?string
    {
        $entry = $this->entries[$issuance->getDeliveryId()->toString()] ?? null;

        return $entry === null ? null : $entry[0]->revealSecret();
    }

    public function remove(AgentIssuance $issuance): void
    {
        $this->assertSupported($issuance);
        $id = $issuance->getDeliveryId()->toString();
        $binding = $this->cleanedBindings[$id] ?? ($this->entries[$id][0] ?? null)?->getIssuance();
        if ($binding !== null && $binding->toArray() !== $issuance->toArray()) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
        }

        ++$this->cleanups;
        $this->cleanedBindings[$id] = $issuance;
        $this->forgetMaterial($issuance);
        $slot = $issuance->getDestination()->getId()->toString();
        $this->highWater[$slot] = max($this->highWater[$slot] ?? 0, $issuance->getDestinationWriteVersion());
        $this->afterCleanup?->__invoke();
    }

    public function forgetMaterial(AgentIssuance $issuance): void
    {
        $this->removed[$issuance->getDeliveryId()->toString()] = true;
        unset($this->entries[$issuance->getDeliveryId()->toString()]);
    }

    private function outsideTransaction(): void
    {
        if ($this->transaction->transactionActive) {
            throw new LogicException('Protected sink calls cannot share a transaction.');
        }
    }
}
