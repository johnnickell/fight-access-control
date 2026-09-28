<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentOperationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;

final class InMemoryAgentOperationRepository implements AgentOperationRepository
{
    /** @var array<string, AgentCredentialOperation> */
    public array $operations = [];

    /** @var array<string, int> */
    public array $versions = [];

    public int $reads = 0;

    public ?Closure $beforeAdd = null;

    public ?Closure $afterAdd = null;

    public ?Closure $afterStatusRead = null;

    public int $statusReads = 0;

    public function __construct(
        private readonly InMemoryUnitOfWork $unitOfWork,
        private readonly InMemoryAgentOperationAuthorization $authorization
    ) {
    }

    public function getByKey(AgentOperationKey $key): ?AgentCredentialOperation
    {
        $this->assertFenced();
        ++$this->reads;

        return $this->operations[$key->toString()] ?? null;
    }

    public function getStatusByKey(AgentOperationKey $key): ?AgentOperationView
    {
        ++$this->statusReads;
        $view = ($this->operations[$key->toString()] ?? null)?->getStatus();
        $this->afterStatusRead?->__invoke();

        return $view;
    }

    public function reserveDestinationWrite(AgentCredentialDestination $destination): int
    {
        $this->assertFenced();
        $key = $destination->getId()->toString();
        $prior = $this->versions[$key] ?? null;
        $version = ($prior ?? 0) + 1;
        $this->versions[$key] = $version;
        $this->unitOfWork->onRollback(function () use ($key, $prior): void {
            if ($prior === null) {
                unset($this->versions[$key]);
            } else {
                $this->versions[$key] = $prior;
            }
        });

        return $version;
    }

    public function add(AgentCredentialOperation $operation, AgentOperationLimits $limits): void
    {
        $this->assertFenced();
        $this->beforeAdd?->__invoke();
        $key = $operation->getIssuance()->getKey();
        if (isset($this->operations[$key->toString()])) {
            throw new AgentOperationCollisionException();
        }

        $pending = 0;
        $scoped = 0;
        foreach ($this->operations as $stored) {
            if ($stored->getIssuance()->getDeliveryId()->equals($operation->getIssuance()->getDeliveryId())) {
                throw new LogicException('Delivery identity must be globally unique.');
            }

            if ($stored->getMaterial() === null) {
                continue;
            }

            ++$pending;
            if ($stored->getIssuance()->getKey()->getScope()->toString() === $key->getScope()->toString()) {
                ++$scoped;
            }
        }

        $limits->validateCapacity($scoped, $pending);
        $this->operations[$key->toString()] = $operation;
        $this->unitOfWork->onRollback(function () use ($key): void {
            unset($this->operations[$key->toString()]);
        });
        $this->afterAdd?->__invoke();
    }

    private function assertFenced(): void
    {
        if (!$this->unitOfWork->transactionActive || !$this->authorization->locked) {
            throw new LogicException('Operation persistence must share authority fences and the package transaction.');
        }
    }
}
