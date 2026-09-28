<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryRewrapper;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentMaintenanceAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use LogicException;
use SensitiveParameter;
use Throwable;

final class MaintenanceEnvironment implements AgentMaintenanceAuthorization, AgentDeliveryRewrapper
{
    public readonly DeliveryEnvironment $delivery;

    public bool $permitted = true;

    public bool $targetPermitted = true;

    public bool $accountingPermitted = true;

    public int $epoch = 1;

    public DateTimeImmutable $expiresAt;

    public int $rewraps = 0;

    public ?AgentDeliveryFailure $failure = null;

    public ?Closure $afterRewrap = null;

    public ?Closure $afterReadAuthorization = null;

    public ?string $wrongVersion = null;

    public function __construct()
    {
        $this->delivery = new DeliveryEnvironment();
        $this->expiresAt = $this->delivery->clock->now()->modify('+30 days');
    }

    public function service(?AgentMaintenancePolicy $policy = null): AgentDeliveryMaintenanceService
    {
        return new AgentDeliveryMaintenanceService(
            $this->delivery->provisioning->operations,
            $this,
            $this,
            $this->delivery->sink,
            $this->delivery->clock,
            $this->delivery->transaction,
            $policy ?? new AgentMaintenancePolicy()
        );
    }

    public function rewrapOriginal(string $version = 'test-key-v2'): AgentMaintenanceResult
    {
        $issuance = $this->delivery->issuance;

        return $this->service()->rewrap(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId(),
            new AgentDeliveryKeyVersion($version)
        );
    }

    public function expire(): AgentMaintenanceResult
    {
        $issuance = $this->delivery->issuance;

        return $this->service()->expire($issuance->getKey(), $issuance->getDestination(), $issuance->getDeliveryId());
    }

    public function cleanup(?AgentMaintenancePolicy $policy = null): AgentMaintenanceResult
    {
        $issuance = $this->delivery->issuance;

        return $this->service($policy)->cleanup(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId()
        );
    }

    public function authorizeRead(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        DateTimeImmutable $now
    ): void {
        if ($this->delivery->provisioning->transaction->transactionActive) {
            throw new LogicException('Maintenance reads cannot mutate.');
        }

        $this->check($scope, $destination, $issuance, $now);
        $this->afterReadAuthorization?->__invoke();
    }

    public function authorizeKeyAccounting(AgentDeliveryKeyVersion $version, DateTimeImmutable $now): void
    {
        if (!$this->accountingPermitted || $now >= $this->expiresAt) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }
    }

    public function authorize(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        ?AgentDeliveryKeyVersion $target,
        DateTimeImmutable $now
    ): AgentDeliveryAuthority {
        $this->check($scope, $destination, $issuance, $now);
        $this->delivery->provisioning->authorization->holdFence();
        if (
            $target !== null
            && ($this->delivery->provisioning->operations->closedKeyVersions[$target->toString()] ?? false)
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        return new AgentDeliveryAuthority('maintainer:'.$this->epoch, $this->expiresAt);
    }

    public function rewrap(
        #[SensitiveParameter] AgentDeliveryMaterial $material,
        AgentIssuance $issuance,
        AgentDeliveryKeyVersion $target
    ): AgentDeliveryMaterial {
        if (!$this->delivery->provisioning->transaction->transactionActive) {
            throw new LogicException('Key references must be fenced.');
        }

        ++$this->rewraps;
        if ($this->failure !== null) {
            throw new AgentDeliveryFailedException($this->failure);
        }

        try {
            $secret = new BoundAgentDeliveryCipher()->inspect($material, $issuance);
        } catch (Throwable) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::CORRUPT_MATERIAL);
        }

        $result = new AgentDeliveryMaterial(
            EncryptedCredentialMaterial::fromString(json_encode([$issuance->toArray(), $secret], JSON_THROW_ON_ERROR)),
            $this->wrongVersion ?? $target->toString()
        );
        $this->afterRewrap?->__invoke();

        return $result;
    }

    private function check(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        DateTimeImmutable $now
    ): void {
        $original = $this->delivery->issuance;
        if (
            !$this->permitted || $now >= $this->expiresAt || ($issuance !== null && !$this->targetPermitted)
            || $scope->toString() !== $original->getKey()->getScope()->toString()
            || $destination->toArray() !== $original->getDestination()->toArray()
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }
    }
}
