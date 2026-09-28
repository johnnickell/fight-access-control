<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryRewrapper;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentDeliveryMaintenanceService
 *
 * Coordinates fenced ciphertext maintenance and separately confirmed external inert-entry cleanup.
 */
final readonly class AgentDeliveryMaintenanceService
{
    /**
     * Constructs AgentDeliveryMaintenanceService
     */
    public function __construct(
        private AgentOperationRepository $operations,
        private AgentMaintenanceAuthorization $authorization,
        private AgentDeliveryRewrapper $rewrapper,
        private AgentCredentialCleanup $cleanup,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private AgentMaintenancePolicy $policy = new AgentMaintenancePolicy()
    ) {
    }

    /**
     * Creates a protected replacement copy or terminal source-loss outcome under current maintenance fences
     */
    public function rewrap(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId,
        AgentDeliveryKeyVersion $target
    ): AgentMaintenanceResult {
        return $this->execute(fn (): AgentMaintenanceResult => $this->transact(function () use (
            $key,
            $destination,
            $deliveryId,
            $target
        ): AgentMaintenanceResult {
            [$original, $authority] = $this->load($key, $destination, $deliveryId, $target);
            if ($original->isMaterialExpired($this->clock->now())) {
                return $this->expireOriginal($original);
            }

            $material = $original->getMaterial();
            if ($material === null || $material->getKeyVersion() === $target->toString()) {
                return AgentMaintenanceResult::UNCHANGED;
            }

            $result = AgentMaintenanceResult::REWRAPPED;
            try {
                $replacementMaterial = $this->rewrapper->rewrap($material, $original->getIssuance(), $target);
                if ($replacementMaterial->getKeyVersion() !== $target->toString()) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                }

                $replacement = $original->rewrapMaterial($replacementMaterial, $this->clock->now());
            } catch (AgentDeliveryFailedException $agentDeliveryFailedException) {
                if (
                    !in_array($agentDeliveryFailedException->getReason(), [
                    AgentDeliveryFailure::KEY_RETIRED,
                    AgentDeliveryFailure::CORRUPT_MATERIAL
                    ], true)
                ) {
                    return AgentMaintenanceResult::RETRYABLE;
                }

                $replacement = $original->failMaterial($agentDeliveryFailedException->getReason());
                $result = AgentMaintenanceResult::TERMINAL;
            }

            $this->assertAuthority($authority);
            $this->operations->replaceMaintenance($original, $replacement);

            return $result;
        }));
    }

    /**
     * Removes expired original material without keys, delivery claims or current slot-reservation eligibility
     */
    public function expire(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId
    ): AgentMaintenanceResult {
        return $this->execute(fn (): AgentMaintenanceResult => $this->transact(function () use (
            $key,
            $destination,
            $deliveryId
        ): AgentMaintenanceResult {
            [$original] = $this->load($key, $destination, $deliveryId);

            return $this->expireOriginal($original);
        }));
    }

    /**
     * Removes terminal inert sink bytes after confirmed authorization and acknowledges without forgetting history
     */
    public function cleanup(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId
    ): AgentMaintenanceResult {
        return $this->execute(function () use ($key, $destination, $deliveryId): AgentMaintenanceResult {
            [$original, $authority] = $this->transact(fn (): array => $this->load($key, $destination, $deliveryId));
            if ($original->isSinkCleaned()) {
                return AgentMaintenanceResult::CLEANED;
            }

            if (!$original->canCleanup($this->clock->now(), $this->policy)) {
                return AgentMaintenanceResult::UNCHANGED;
            }

            $this->assertAuthority($authority);
            $this->cleanup->remove($original->getIssuance());

            return $this->transact(function () use (
                $key,
                $destination,
                $deliveryId,
                $original,
                $authority
            ): AgentMaintenanceResult {
                [$current, $currentAuthority] = $this->load($key, $destination, $deliveryId);
                $this->assertAuthority($authority);
                if (
                    $currentAuthority->getEpoch() !== $authority->getEpoch()
                    || $current->getStateRevision() !== $original->getStateRevision()
                ) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
                }

                $this->operations->replaceMaintenance(
                    $current,
                    $current->confirmCleanup($this->clock->now(), $this->policy)
                );

                return AgentMaintenanceResult::CLEANED;
            });
        });
    }

    /**
     * Retrieves exact original correlation after scope authorization and fences target authority before any effect
     *
     * @return array{AgentCredentialOperation, AgentDeliveryAuthority}
     */
    private function load(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId,
        ?AgentDeliveryKeyVersion $target = null
    ): array {
        $this->authorization->authorize($key->getScope(), $destination, null, $target, $this->clock->now());
        $original = $this->operations->getByKey($key);
        if ($original === null || !$original->getIssuance()->getDeliveryId()->equals($deliveryId)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $original->getStatus()->assertReadable($key, $destination);
        $authority = $this->authorization->authorize(
            $key->getScope(),
            $destination,
            $original->getIssuance(),
            $target,
            $this->clock->now()
        );
        $this->assertAuthority($authority);

        return [$original, $authority];
    }

    /**
     * Creates expiry only when the aggregate requires a durable transition
     */
    private function expireOriginal(#[SensitiveParameter] AgentCredentialOperation $original): AgentMaintenanceResult
    {
        $replacement = $original->expireMaterial($this->clock->now());
        if ($replacement === $original) {
            return AgentMaintenanceResult::UNCHANGED;
        }

        $this->operations->replaceMaintenance($original, $replacement);

        return AgentMaintenanceResult::EXPIRED;
    }

    /**
     * Validates trusted time after a slow commit or key/sink call
     */
    private function assertAuthority(AgentDeliveryAuthority $authority): void
    {
        if ($this->clock->now() >= $authority->getExpiresAt()) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }
    }

    /**
     * Executes one transaction without guessing rollback when commit acknowledgement is missing
     *
     * @param callable(): T $operation
     *
     * @template T
     * @return T
     */
    private function transact(#[SensitiveParameter] callable $operation): mixed
    {
        if ($this->unitOfWork->isClosed()) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }

        $completed = false;
        try {
            return $this->unitOfWork->commitTransactional(static function () use ($operation, &$completed): mixed {
                $result = $operation();
                $completed = true;

                return $result;
            });
        } catch (Throwable $throwable) {
            if ($completed) {
                throw new AgentDeliveryCommitUncertainException();
            }

            throw $throwable;
        }
    }

    /**
     * Returns only safe classifications without publishing or chaining dependency diagnostics
     *
     * @param callable(): AgentMaintenanceResult $operation
     */
    private function execute(#[SensitiveParameter] callable $operation): AgentMaintenanceResult
    {
        try {
            return $operation();
        } catch (AgentDeliveryCommitUncertainException) {
            return AgentMaintenanceResult::INDETERMINATE;
        } catch (AgentDeliveryFailedException) {
            return AgentMaintenanceResult::RETRYABLE;
        } catch (AgentOperationRejectedException $failure) {
            if ($failure->getReason() !== AgentOperationFailure::UNAVAILABLE) {
                return AgentMaintenanceResult::REJECTED;
            }
        } catch (Throwable) {
            // Preserve original state after unclassified failure; never leak a provider trace or infer completion.
        }

        return AgentMaintenanceResult::UNAVAILABLE;
    }
}
