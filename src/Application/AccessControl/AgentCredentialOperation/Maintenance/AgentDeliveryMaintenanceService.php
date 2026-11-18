<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentMaintenanceOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use LogicException;
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
        private AgentCredentialOperationRepository $operationRepository,
        private AgentMaintenanceAuthorization $maintenanceAuthorization,
        private AgentDeliveryRewrapper $rewrapper,
        private AgentCredentialCleanup $cleanup,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private AgentMaintenancePolicy $policy = new AgentMaintenancePolicy(),
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * Creates a protected replacement copy or terminal source-loss outcome under current maintenance fences
     */
    public function rewrap(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId,
        AgentDeliveryKeyVersion $target,
        UserId|AgentId|SystemId|null $actorId = null
    ): AgentMaintenanceResult {
        return $this->execute(function (AgentDeliveryCommitUncertainException $commitUncertain) use (
            $key,
            $destination,
            $deliveryId,
            $target,
            $actorId
        ): AgentMaintenanceResult {
            /** @var AgentMaintenanceResult $result */
            $result = $this->transact(function () use (
                $key,
                $destination,
                $deliveryId,
                $target,
                $actorId
            ): AgentMaintenanceResult {
                [$original, $authority] = $this->load($key, $destination, $deliveryId);
                if ($original->isMaterialExpired($this->now())) {
                    return $this->expireOriginal($original, $authority, $actorId);
                }

                $authority = $this->operationContract()->bindAuthority(
                    $this->authorize($key, $destination, $original->getIssuance(), $target)
                );
                $material = $original->getMaterial();
                if ($material === null || $material->getKeyVersion() === $target->toString()) {
                    return AgentMaintenanceResult::UNCHANGED;
                }

                $this->requireActor($actorId);
                try {
                    $rewrapped = $this->rewrapper->rewrap($material, $original->getIssuance(), $target);
                } catch (AgentOperationRejectedException $failure) {
                    if ($failure->getReason() === AgentOperationFailure::CONTENTION) {
                        throw $failure;
                    }

                    throw new LogicException('The delivery rewrapper failure contract failed.');
                } catch (AgentDeliveryFailedException $agentDeliveryFailedException) {
                    if (
                        !in_array($agentDeliveryFailedException->getReason(), [
                        AgentDeliveryFailure::KEY_RETIRED,
                        AgentDeliveryFailure::CORRUPT_MATERIAL
                        ], true)
                    ) {
                        if ($agentDeliveryFailedException->getReason() !== AgentDeliveryFailure::TEMPORARY) {
                            throw new LogicException('The delivery rewrapper contract failed.');
                        }

                        return AgentMaintenanceResult::RETRYABLE;
                    }

                    $rewrapped = $agentDeliveryFailedException->getReason();
                }

                $occurredAt = $this->now();
                $result = AgentMaintenanceResult::REWRAPPED;
                $action = AuditAction::AGENT_MATERIAL_REWRAPPED;
                if ($rewrapped instanceof AgentDeliveryFailure) {
                    $replacement = $original->failMaterial($rewrapped);
                    $result = AgentMaintenanceResult::TERMINAL;
                    $action = AuditAction::AGENT_MATERIAL_FAILED;
                } else {
                    if ($rewrapped->getKeyVersion() !== $target->toString()) {
                        throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
                    }

                    $replacement = $original->rewrapMaterial($rewrapped, $occurredAt);
                }

                $this->assertAuthority($authority);
                $this->replaceMaintenance($original, $replacement);
                $this->record($original, $replacement, $action, $occurredAt, $actorId);
                $this->assertAuthority($authority);

                return $result;
            }, $commitUncertain);

            return $result;
        });
    }

    /**
     * Removes expired original material without keys, delivery claims or current slot-reservation eligibility
     */
    public function expire(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId,
        UserId|AgentId|SystemId|null $actorId = null
    ): AgentMaintenanceResult {
        return $this->execute(function (AgentDeliveryCommitUncertainException $commitUncertain) use (
            $key,
            $destination,
            $deliveryId,
            $actorId
        ): AgentMaintenanceResult {
            /** @var AgentMaintenanceResult $result */
            $result = $this->transact(function () use (
                $key,
                $destination,
                $deliveryId,
                $actorId
            ): AgentMaintenanceResult {
                [$original, $authority] = $this->load($key, $destination, $deliveryId);

                return $this->expireOriginal($original, $authority, $actorId);
            }, $commitUncertain);

            return $result;
        });
    }

    /**
     * Removes terminal inert sink bytes after confirmed authorization and acknowledges without forgetting history
     */
    public function cleanup(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId,
        UserId|AgentId|SystemId|null $actorId = null
    ): AgentMaintenanceResult {
        return $this->execute(function (AgentDeliveryCommitUncertainException $commitUncertain) use (
            $key,
            $destination,
            $deliveryId,
            $actorId
        ): AgentMaintenanceResult {
            /** @var array{AgentCredentialOperation, AgentDeliveryAuthority} $admission */
            $admission = $this->transact(function () use ($key, $destination, $deliveryId, $actorId): array {
                $loaded = $this->load($key, $destination, $deliveryId);
                if ($loaded[0]->canCleanup($this->now(), $this->policy)) {
                    $this->requireActor($actorId);
                }

                return $loaded;
            }, $commitUncertain);
            [$original, $authority] = $admission;
            if ($original->isSinkCleaned()) {
                return AgentMaintenanceResult::CLEANED;
            }

            if (!$original->canCleanup($this->now(), $this->policy)) {
                return AgentMaintenanceResult::UNCHANGED;
            }

            $this->requireActor($actorId);
            $this->operationContract()->assertCompatible();
            $this->assertAuthority($authority);
            try {
                $this->cleanup->remove($original->getIssuance());
            } catch (AgentDeliveryFailedException $failure) {
                return match ($failure->getReason()) {
                    AgentDeliveryFailure::TEMPORARY,
                    AgentDeliveryFailure::UNSUPPORTED_SINK => AgentMaintenanceResult::RETRYABLE,
                    AgentDeliveryFailure::INVALID_RECEIPT => AgentMaintenanceResult::REJECTED,
                    default => AgentMaintenanceResult::UNAVAILABLE
                };
            } catch (AgentOperationRejectedException) {
                throw new LogicException('The protected cleanup failure contract failed.');
            }

            /** @var AgentMaintenanceResult $result */
            $result = $this->transact(function () use (
                $key,
                $destination,
                $deliveryId,
                $original,
                $authority,
                $actorId
            ): AgentMaintenanceResult {
                [$current, $currentAuthority] = $this->load($key, $destination, $deliveryId);
                $this->assertAuthority($authority);
                if (
                    $currentAuthority->getEpoch() !== $authority->getEpoch()
                    || $current->getStateRevision() !== $original->getStateRevision()
                ) {
                    throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
                }

                $occurredAt = $this->now();
                $replacement = $current->confirmCleanup($occurredAt, $this->policy);
                $this->replaceMaintenance($current, $replacement);
                $this->record($current, $replacement, AuditAction::AGENT_SINK_CLEANED, $occurredAt, $actorId);
                $this->assertAuthority($authority);
                $this->assertAuthority($currentAuthority);

                return AgentMaintenanceResult::CLEANED;
            }, $commitUncertain);

            return $result;
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
        $this->authorize($key, $destination, null, $target);
        try {
            $original = $this->operationRepository->getByKey($key);
        } catch (AgentOperationRejectedException) {
            throw new LogicException('The operation lookup failed.');
        }

        if ($original !== null && $original->getIssuance()->getKey()->toString() !== $key->toString()) {
            throw new LogicException('The operation lookup identity is inconsistent.');
        }

        if ($original === null || !$original->getIssuance()->getDeliveryId()->equals($deliveryId)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        AgentOperationView::fromOperation($original)->assertReadable($key, $destination);
        $authority = $this->operationContract()->bindAuthority(
            $this->authorize($key, $destination, $original->getIssuance(), $target)
        );
        $this->assertAuthority($authority);

        return [$original, $authority];
    }

    /**
     * Validates authorization failure intent and deadline before a concealed read or effect
     */
    private function authorize(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        ?AgentDeliveryKeyVersion $target
    ): AgentDeliveryAuthority {
        $now = $this->now();
        try {
            $authority = $this->maintenanceAuthorization->authorize(
                $key->getScope(),
                $destination,
                $issuance,
                $target,
                $now
            );
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            if (
                !in_array($agentOperationRejectedException->getReason(), [
                AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::CONFLICT, AgentOperationFailure::UNAVAILABLE
                ], true)
            ) {
                throw new LogicException('The maintenance authorization contract failed.');
            }

            throw $agentOperationRejectedException;
        }

        $this->assertAuthority($authority);

        return $authority;
    }

    /**
     * Creates expiry only when the aggregate requires a durable transition
     */
    private function expireOriginal(
        #[SensitiveParameter] AgentCredentialOperation $original,
        AgentDeliveryAuthority $authority,
        UserId|AgentId|SystemId|null $actorId
    ): AgentMaintenanceResult {
        $occurredAt = $this->now();
        $replacement = $original->expireMaterial($occurredAt);
        if ($replacement === $original) {
            return AgentMaintenanceResult::UNCHANGED;
        }

        $this->requireActor($actorId);
        $this->replaceMaintenance($original, $replacement);
        $this->record($original, $replacement, AuditAction::AGENT_MATERIAL_EXPIRED, $occurredAt, $actorId);
        $this->assertAuthority($authority);

        return AgentMaintenanceResult::EXPIRED;
    }

    /**
     * Validates explicit provenance only before enabled mutation or external-effect admission
     */
    private function requireActor(UserId|AgentId|SystemId|null $actorId): void
    {
        if ($this->auditEvidenceRepository !== null && $actorId === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }
    }

    /**
     * Records one real outcome inside its owning expected-state transaction
     */
    private function record(
        #[SensitiveParameter] AgentCredentialOperation $original,
        #[SensitiveParameter] AgentCredentialOperation $replacement,
        AuditAction $action,
        DateTimeImmutable $occurredAt,
        UserId|AgentId|SystemId|null $actorId
    ): void {
        if ($this->auditEvidenceRepository === null || $actorId === null) {
            return;
        }

        $materialBefore = $original->getMaterial();
        $materialAfter = $replacement->getMaterial();
        $evidence = AuditEvidence::record(
            $actorId,
            new AuditSubject($original->getIssuance()->getAgentId()),
            $action,
            $occurredAt,
            new AuditAgentMaintenanceOutcome(
                $original->getIssuance()->getCredentialId(),
                $original->getIssuance()->getDeliveryId(),
                $original->getStateRevision(),
                $replacement->getStateRevision(),
                $materialBefore === null ? null : new AgentDeliveryKeyVersion($materialBefore->getKeyVersion()),
                $materialAfter === null ? null : new AgentDeliveryKeyVersion($materialAfter->getKeyVersion()),
                $replacement->getDeliveryFailure()
            ),
            new AuditCorrelation(null, $original->getIssuance()->getKey())
        );
        try {
            $this->auditEvidenceRepository->add($evidence);
        } catch (Throwable) {
            throw new LogicException('The maintenance audit storage is unavailable.');
        }
    }

    /**
     * Validates trusted time after a slow commit or key/sink call
     */
    private function assertAuthority(AgentDeliveryAuthority $authority): void
    {
        if ($this->now() >= $authority->getExpiresAt()) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }
    }

    /**
     * Retrieves cohort evidence without treating a failed capability lookup as maintenance refusal
     */
    private function operationContract(): AgentOperationContract
    {
        try {
            return $this->operationRepository->getOperationContract();
        } catch (AgentOperationRejectedException) {
            throw new LogicException('The operation cohort is unavailable.');
        }
    }

    /**
     * Persists only the maintenance writer's deliberate expected-state refusal meanings
     */
    private function replaceMaintenance(
        #[SensitiveParameter] AgentCredentialOperation $original,
        #[SensitiveParameter] AgentCredentialOperation $replacement
    ): void {
        try {
            $this->operationRepository->replaceMaintenance($original, $replacement);
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            if (
                !in_array($agentOperationRejectedException->getReason(), [
                AgentOperationFailure::UNAUTHORIZED,
                AgentOperationFailure::CONFLICT,
                AgentOperationFailure::CONTENTION,
                AgentOperationFailure::UNAVAILABLE
                ], true)
            ) {
                throw new LogicException('The maintenance write contract failed.');
            }

            throw $agentOperationRejectedException;
        }
    }

    /**
     * Executes one transaction without guessing rollback when commit acknowledgement is missing
     *
     * @phpstan-param callable(): (AgentMaintenanceResult|array{
     *     AgentCredentialOperation, AgentDeliveryAuthority
     * }) $operation
     */
    private function transact(
        #[SensitiveParameter] callable $operation,
        AgentDeliveryCommitUncertainException $commitUncertain
    ): mixed {
        $completed = false;
        $callbackFailure = null;
        try {
            if ($this->unitOfWork->isClosed()) {
                throw new LogicException('The maintenance transaction is closed.');
            }

            return $this->unitOfWork->commitTransactional(function () use (
                $operation,
                &$completed,
                &$callbackFailure
            ): mixed {
                try {
                    $this->operationContract()->assertCompatible();
                    $result = $operation();
                    $completed = true;

                    return $result;
                } catch (Throwable $throwable) {
                    $callbackFailure = $throwable;
                    throw $throwable;
                }
            });
        } catch (Throwable) {
            /** @var bool $completed Updated by the owned callback before commit acknowledgement */
            if ($completed) {
                // Only this invocation's private marker proves owned callback completion without acknowledgement.
                throw $commitUncertain;
            }

            /** @var Throwable|null $callbackFailure Captured at the owned callback, not inferred from UoW mechanics */
            if ($callbackFailure !== null) {
                throw $callbackFailure;
            }

            throw new LogicException('The maintenance transaction is unavailable.');
        }
    }

    /**
     * Returns trusted time without adopting failure intent from the Clock
     */
    private function now(): DateTimeImmutable
    {
        try {
            return $this->clock->now();
        } catch (Throwable) {
            throw new LogicException('The maintenance clock is unavailable.');
        }
    }

    /**
     * Returns only safe classifications without publishing or chaining dependency diagnostics
     *
     * @param callable(AgentDeliveryCommitUncertainException): AgentMaintenanceResult $operation
     */
    private function execute(#[SensitiveParameter] callable $operation): AgentMaintenanceResult
    {
        $commitUncertain = new AgentDeliveryCommitUncertainException();
        try {
            return $operation($commitUncertain);
        } catch (Throwable $throwable) {
            if ($throwable === $commitUncertain) {
                return AgentMaintenanceResult::INDETERMINATE;
            }

            if (!$throwable instanceof AgentOperationRejectedException) {
                return AgentMaintenanceResult::UNAVAILABLE;
            }

            if (
                in_array($throwable->getReason(), [
                AgentOperationFailure::UNAUTHORIZED,
                AgentOperationFailure::CONFLICT,
                AgentOperationFailure::CONTENTION,
                AgentOperationFailure::UNSUPPORTED_VERSION
                ], true)
            ) {
                return AgentMaintenanceResult::REJECTED;
            }
        }

        return AgentMaintenanceResult::UNAVAILABLE;
    }
}
