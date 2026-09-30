<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentOperationContract;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use LogicException;

/**
 * Models an independent recovery journal and admission boundary; NOT a real backup tool or reconciliation service
 *
 * The retained latest snapshot is a test oracle for lossless forward repair, outside the restored dataset. It proves
 * only these modeled histories, not that a consumer can obtain such a journal or reconstruct missing production facts.
 */
final class InMemoryAgentRestoration implements AgentRestorationFixture
{
    /** @var array<string, InMemoryAgentRestorationSnapshot> */
    private array $checkpoints = [];

    private ?InMemoryAgentRestorationSnapshot $required = null;

    /** @var list<InMemoryAgentRestorationSnapshot> */
    private array $observations = [];

    private int $trustedGeneration = 1;

    private ?string $failure = null;

    public function __construct(private readonly DeliveryEnvironment $environment)
    {
    }

    public function savePackageState(string $checkpoint): void
    {
        $this->assertQuiescent();
        $this->checkpoints[$checkpoint] = InMemoryAgentRestorationSnapshot::capture($this->environment->provisioning);
    }

    public function restorePackageState(string $checkpoint): void
    {
        $this->assertQuiescent();
        $snapshot = $this->checkpoints[$checkpoint] ?? throw new LogicException('Missing restore checkpoint.');
        $env = $this->environment->provisioning;
        $this->required ??= InMemoryAgentRestorationSnapshot::capture($env);
        $this->trustedGeneration = max($this->trustedGeneration, $env->operations->contract->generation) + 1;
        // This independently held evidence is deliberately NOT part of snapshot->restore().
        $env->operations->contract->reconciledGeneration = null;
        $snapshot->restore($env);
    }

    public function reconcilePackageState(string $checkpoint): bool
    {
        $this->assertQuiescent();
        $snapshot = $this->checkpoints[$checkpoint] ?? throw new LogicException('Missing repair checkpoint.');
        if ($this->required === null || $snapshot != $this->required || !$this->hasVerifiedSinkEvidence($snapshot)) {
            return false;
        }

        $snapshot->contract?->assertCompatible();
        $env = $this->environment->provisioning;
        $snapshot->restore($env);
        $state = $env->operations->contract;
        $state->generation = $this->trustedGeneration;
        $state->current = InMemoryAgentOperationContract::compatible($this->trustedGeneration);
        $state->reconciledGeneration = $this->trustedGeneration;

        $this->required = null;

        return true;
    }

    public function packageStateFingerprint(): string
    {
        $current = InMemoryAgentRestorationSnapshot::capture($this->environment->provisioning);
        foreach ($this->observations as $index => $snapshot) {
            if ($snapshot == $current) {
                return 'state:'.$index;
            }
        }

        $index = count($this->observations);
        $this->observations[] = $current;

        return 'state:'.$index;
    }

    public function breakRestorationEvidence(string $failure): void
    {
        $this->failure = $failure;
    }

    private function assertQuiescent(): void
    {
        $env = $this->environment->provisioning;
        if ($env->transaction->transactionActive || $env->operations->contract->locked) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONTENTION);
        }
    }

    private function hasVerifiedSinkEvidence(InMemoryAgentRestorationSnapshot $snapshot): bool
    {
        $actual = $this->environment->sink->restorationEvidence();
        $observed = $actual;
        if ($this->failure === 'unavailable' || $this->failure === 'missing') {
            return false;
        }

        if ($this->failure === 'order') {
            $observed['order'] = [];
        } elseif ($this->failure === 'tombstone') {
            $observed['removed'] = [];
            $observed['cleaned'] = [];
        } elseif ($this->failure !== null) {
            foreach ($observed['entries'] as &$entry) {
                if ($this->failure === 'receipt') {
                    $entry[1] = 'unverified-receipt';
                } else {
                    $entry[0][$this->failure] = 'mismatched-binding';
                }
            }

            unset($entry);
        }

        if ($observed !== $actual) {
            return false;
        }

        $byDelivery = [];
        foreach ($snapshot->operations as $operation) {
            $byDelivery[$operation->getIssuance()->getDeliveryId()->toString()] = $operation;
        }

        foreach ($observed['entries'] as $id => [$binding, $receipt]) {
            $operation = $byDelivery[$id] ?? null;
            if (
                $operation === null || $operation->getIssuance()->toArray() !== $binding
                || ($operation->getReceipt() !== null && $operation->getReceipt()->toString() !== $receipt)
            ) {
                return false;
            }
        }

        foreach ($observed['cleaned'] as $id => $binding) {
            $operation = $byDelivery[$id] ?? null;
            if (
                $operation === null || $operation->getIssuance()->toArray() !== $binding
                || $operation->getMaterial() !== null || !isset($observed['removed'][$id])
            ) {
                return false;
            }
        }

        return array_all(
            $observed['order'],
            static fn (int $version, string $slot): bool => ($snapshot->versions[$slot] ?? 0) >= $version
        );
    }
}
