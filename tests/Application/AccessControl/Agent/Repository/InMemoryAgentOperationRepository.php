<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationCollisionException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentOperationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use SensitiveParameter;
use Throwable;

final class InMemoryAgentOperationRepository implements AgentOperationRepository
{
    public readonly InMemoryAgentOperationContract $contract;

    /** @var array<string, AgentCredentialOperation> */
    public array $operations = [];

    /** @var array<string, int> */
    public array $versions = [];

    public int $reads = 0;

    public ?Closure $beforeAdd = null;

    public ?Closure $afterAdd = null;

    public ?Closure $afterStatusRead = null;

    public int $statusReads = 0;

    public int $dueReads = 0;

    public ?Closure $afterDueRead = null;

    public ?Closure $afterRetirement = null;

    public bool $retirementLocked = false;

    public ?InMemoryAgentRepository $agents = null;

    public ?Closure $beforeDeliveryWrite = null;

    public ?Closure $afterDeliveryWrite = null;

    public int $deliveryWrites = 0;

    public int $maintenanceWrites = 0;

    public ?Closure $beforeMaintenanceWrite = null;

    public ?Closure $afterMaintenanceWrite = null;

    public ?Closure $afterMaintenanceRead = null;

    /** @var array<string, bool> */
    public array $closedKeyVersions = [];

    public function __construct(
        private readonly InMemoryUnitOfWork $unitOfWork,
        private readonly InMemoryAgentOperationAuthorization $authorization
    ) {
        $this->contract = new InMemoryAgentOperationContract($unitOfWork);
    }

    public function getOperationContract(): AgentOperationContract
    {
        return $this->contract->read();
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

    public function listDueDeliveries(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        DateTimeImmutable $now,
        int $limit
    ): array {
        if ($this->unitOfWork->transactionActive) {
            throw new LogicException('Discovery cannot share a transaction.');
        }

        ++$this->dueReads;
        $currentWrite = $this->versions[$destination->getId()->toString()] ?? null;
        $due = array_filter($this->operations, static function (AgentCredentialOperation $operation) use (
            $scope,
            $destination,
            $now,
            $currentWrite
        ): bool {
            $issuance = $operation->getIssuance();

            return $issuance->getKey()->getScope()->toString() === $scope->toString()
                && $issuance->getDestination()->toArray() === $destination->toArray()
                && $issuance->getDestinationWriteVersion() === $currentWrite
                && $operation->getDeliveryDueAt() !== null && $operation->getDeliveryDueAt() <= $now;
        });
        usort($due, static function (AgentCredentialOperation $left, AgentCredentialOperation $right): int {
            $dueOrder = $left->getDeliveryDueAt() <=> $right->getDeliveryDueAt();

            return $dueOrder ?: strcmp(
                $left->getIssuance()->getDeliveryId()->toString(),
                $right->getIssuance()->getDeliveryId()->toString()
            );
        });
        $views = array_map(
            static fn (AgentCredentialOperation $operation): AgentOperationView => $operation->getStatus(),
            array_slice($due, 0, $limit)
        );
        $this->afterDueRead?->__invoke();

        return $views;
    }

    public function listMaintenance(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        AgentMaintenanceWork $work,
        DateTimeImmutable $now,
        AgentMaintenancePolicy $policy,
        ?AgentDeliveryId $after
    ): array {
        $selected = array_filter($this->operations, static function (AgentCredentialOperation $operation) use (
            $scope,
            $destination,
            $work,
            $now,
            $policy,
            $after
        ): bool {
            $issuance = $operation->getIssuance();
            $applicable = $operation->getMaterial() !== null;
            if ($work === AgentMaintenanceWork::CLEANUP) {
                $applicable = $operation->canCleanup($now, $policy);
            }

            return $issuance->getKey()->getScope()->toString() === $scope->toString()
                && $issuance->getDestination()->toArray() === $destination->toArray()
                && ($after === null || strcmp($issuance->getDeliveryId()->toString(), $after->toString()) > 0)
                && $applicable;
        });
        usort($selected, static fn (AgentCredentialOperation $left, AgentCredentialOperation $right): int => strcmp(
            $left->getIssuance()->getDeliveryId()->toString(),
            $right->getIssuance()->getDeliveryId()->toString()
        ));
        $views = array_map(
            static fn (AgentCredentialOperation $operation): AgentOperationView => $operation->getStatus(),
            array_slice($selected, 0, $policy->getBatchSize())
        );
        $this->afterMaintenanceRead?->__invoke();

        return $views;
    }

    public function countDeliveryKeyReferences(AgentDeliveryKeyVersion $version): int
    {
        $count = count(array_filter($this->operations, static fn (AgentCredentialOperation $operation): bool =>
            $operation->getMaterial()?->getKeyVersion() === $version->toString()));
        $this->afterMaintenanceRead?->__invoke();

        return $count;
    }

    public function replaceMaintenance(
        #[SensitiveParameter] AgentCredentialOperation $expected,
        #[SensitiveParameter] AgentCredentialOperation $replacement
    ): void {
        $this->assertFenced();
        $this->beforeMaintenanceWrite?->__invoke();
        $key = $expected->getIssuance()->getKey()->toString();
        if (
            ($this->operations[$key] ?? null) !== $expected
            || $replacement->getStateRevision() !== $expected->getStateRevision() + 1
            || $expected->getIssuance()->toArray() !== $replacement->getIssuance()->toArray()
            || $expected->getCanonicalRequest() !== $replacement->getCanonicalRequest()
            || $expected->getCanonicalVersion() !== $replacement->getCanonicalVersion()
            || ($expected->getMaterial() === null && $replacement->getMaterial() !== null)
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $this->assertWritableKey($replacement);
        $this->operations[$key] = $replacement;
        ++$this->maintenanceWrites;
        $this->unitOfWork->onRollback(function () use ($key, $expected): void {
            $this->operations[$key] = $expected;
        });
        $this->afterMaintenanceWrite?->__invoke();
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
        $this->assertWritableKey($operation);
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

    public function participatesIn(?InMemoryUnitOfWork $unitOfWork): bool
    {
        return $unitOfWork === $this->unitOfWork && $this->unitOfWork->transactionActive;
    }

    public function retireCredential(
        #[SensitiveParameter] Agent $expected,
        #[SensitiveParameter] Agent $replacement
    ): void {
        $this->getOperationContract()->assertCompatible();
        if (!$this->unitOfWork->transactionActive) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }

        $this->retirementLocked = true;
        $this->authorization->holdFence();
        $this->unitOfWork->onCompletion(function (): void {
            $this->retirementLocked = false;
        });
        $matches = array_filter($this->operations, static function (AgentCredentialOperation $operation) use (
            $expected
        ): bool {
            $issuance = $operation->getIssuance();

            return $issuance->getAgentId()->equals($expected->getId())
                && $issuance->getCredentialId()->equals($expected->getCredentialId())
                && $issuance->getCredentialRevision() === $expected->getCredentialRevision();
        });
        if (count($matches) !== 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $key = array_key_first($matches);
        $original = $matches[$key];
        $this->operations[$key] = $original->retireCredential($expected, $replacement);
        $this->unitOfWork->onRollback(function () use ($key, $original): void {
            $this->operations[$key] = $original;
        });
        try {
            $this->afterRetirement?->__invoke();
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }
    }

    public function replaceDelivery(
        AgentCredentialOperation $expected,
        AgentCredentialOperation $replacement,
        #[SensitiveParameter] Agent $expectedAgent
    ): void {
        $this->assertFenced();
        $this->beforeDeliveryWrite?->__invoke();
        $key = $expected->getIssuance()->getKey()->toString();
        if (
            ($this->operations[$key] ?? null) !== $expected
            || $this->agents?->getById($expectedAgent->getId()) !== $expectedAgent
            || $replacement->getStateRevision() !== $expected->getStateRevision() + 1
            || $expected->getIssuance()->toArray() !== $replacement->getIssuance()->toArray()
            || !$expected->hasPendingDeliveryAtRevision($expected->getStateRevision())
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $expected->assertDeliveryCredential($expectedAgent);
        // Existing references may complete/retry while key admission is closed to NEW references.
        $this->operations[$key] = $replacement;
        ++$this->deliveryWrites;
        $this->unitOfWork->onRollback(function () use ($key, $expected): void {
            $this->operations[$key] = $expected;
        });
        $this->afterDeliveryWrite?->__invoke();
    }

    private function assertWritableKey(#[SensitiveParameter] AgentCredentialOperation $operation): void
    {
        $version = $operation->getMaterial()?->getKeyVersion();
        if ($version !== null && ($this->closedKeyVersions[$version] ?? false)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }
    }

    private function assertFenced(): void
    {
        $this->getOperationContract()->assertCompatible();
        if (!$this->unitOfWork->transactionActive || !$this->authorization->locked) {
            throw new LogicException('Operation persistence must share authority fences and the package transaction.');
        }
    }
}
