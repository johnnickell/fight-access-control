<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialReceiptLookup;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentCredentialDeliveryService
 *
 * Commits claim, admission and outcome separately around one outside-transaction protected sink attempt.
 */
final readonly class AgentCredentialDeliveryService
{
    /**
     * Constructs AgentCredentialDeliveryService
     */
    public function __construct(
        private AgentRepository $agents,
        private AgentOperationRepository $operations,
        private AgentOperationAuthorization $scopeAuthorization,
        private AgentDeliveryAuthorization $deliveryAuthorization,
        private AgentDeliveryDecipher $decipher,
        private AgentCredentialSink $sink,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private AgentDeliveryPolicy $policy = new AgentDeliveryPolicy()
    ) {
    }

    /**
     * Handles one exact original delivery under the real worker's current delegated authority
     */
    public function deliver(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId
    ): AgentDeliveryResult {
        try {
            $claimed = $this->transact(function () use ($key, $destination, $deliveryId): AgentCredentialOperation {
                $original = $this->load($key, $destination, $deliveryId);
                $agent = $this->currentAgent($original);
                $authority = $this->deliveryAuthorization->authorize($original->getIssuance(), $this->clock->now());
                if (
                    !$original->hasPendingDeliveryAtRevision($original->getStateRevision())
                    || $original->canReconcileDelivery($authority, $this->clock->now())
                ) {
                    return $original;
                }

                $replacement = $original->claimDelivery(
                    AgentDeliveryClaimId::generate(),
                    $this->policy,
                    $this->clock->now()
                );
                $this->operations->replaceDelivery($original, $replacement, $agent);

                return $replacement;
            });
            if (!$claimed->hasPendingDeliveryAtRevision($claimed->getStateRevision())) {
                return $this->reconcileRecordedResult($claimed);
            }

            $receiptOnly = $claimed->requireAttempt()->getAuthority() !== null;
            $admitted = $claimed;
            if (!$receiptOnly) {
                $admitted = $this->admit($key, $destination, $deliveryId, $claimed);
            }

            $outcome = $this->invoke($admitted, $receiptOnly);
            if ($outcome === null) {
                return AgentDeliveryResult::DEFERRED;
            }

            $completed = $this->transact(function () use ($key, $destination, $deliveryId, $admitted, $outcome) {
                $original = $this->load($key, $destination, $deliveryId);
                $agent = $this->currentAgent($original);
                $authority = $this->deliveryAuthorization->authorize($original->getIssuance(), $this->clock->now());
                $attempt = $admitted->requireAttempt();
                $replacement = $original->finishDelivery(
                    $attempt,
                    $authority,
                    $outcome,
                    $this->clock->now(),
                    $admitted->getStateRevision()
                );
                $this->operations->replaceDelivery($original, $replacement, $agent);

                return $replacement;
            });

            return $this->recordedResult($completed);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AgentDeliveryCommitUncertainException) {
                return AgentDeliveryResult::INDETERMINATE;
            }

            if ($throwable instanceof AgentOperationRejectedException) {
                if ($throwable->getReason() === AgentOperationFailure::CONTENTION) {
                    return AgentDeliveryResult::DEFERRED;
                }

                if ($throwable->getReason() !== AgentOperationFailure::UNAVAILABLE) {
                    return AgentDeliveryResult::REJECTED;
                }
            }

            // Never return or chain a dependency exception, whose trace may contain credential material.
            return AgentDeliveryResult::UNAVAILABLE;
        }
    }

    /**
     * Creates a fresh confirmed admission rather than treating recovered state as permission to invoke
     */
    private function admit(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId,
        AgentCredentialOperation $claimed
    ): AgentCredentialOperation {
        return $this->transact(function () use ($key, $destination, $deliveryId, $claimed) {
            $original = $this->load($key, $destination, $deliveryId);
            $agent = $this->currentAgent($original);
            $authority = $this->deliveryAuthorization->authorize($original->getIssuance(), $this->clock->now());
            $replacement = $original->admitDelivery(
                $claimed->requireAttempt(),
                $authority,
                $this->clock->now(),
                $claimed->getStateRevision()
            );
            $this->operations->replaceDelivery($original, $replacement, $agent);

            return $replacement;
        });
    }

    /**
     * Retrieves exact retained correlation only after current scope authorization
     */
    private function load(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId
    ): AgentCredentialOperation {
        $this->scopeAuthorization->authorize($key->getScope(), $destination);
        $operation = $this->operations->getByKey($key);
        if ($operation === null || !$operation->getIssuance()->getDeliveryId()->equals($deliveryId)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $operation->getStatus()->assertReadable($key, $destination);

        return $operation;
    }

    /**
     * Validates the authoritative Agent whose state the repository must fence through the delivery write
     */
    private function currentAgent(AgentCredentialOperation $operation): Agent
    {
        $agent = $this->agents->getById($operation->getIssuance()->getAgentId());
        if ($agent === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        $operation->assertDeliveryCredential($agent);

        return $agent;
    }

    /**
     * Creates and invokes sensitive material only after confirmed admission and repeated deadline checks
     */
    private function invoke(
        AgentCredentialOperation $admitted,
        bool $receiptOnly
    ): AgentDeliveryReceipt|AgentDeliveryFailure|null {
        $attempt = $admitted->requireAttempt();
        $attempt->assertAdmittedAt($this->clock->now());
        try {
            $issuance = $admitted->getIssuance();
            $this->sink->assertSupported($issuance);
            $attempt->assertAdmittedAt($this->clock->now());
            if ($this->sink instanceof AgentCredentialReceiptLookup) {
                $receipt = $this->sink->lookupReceipt($issuance);
                if ($receipt !== null) {
                    if (!$this->sink->verify($receipt, $issuance)) {
                        throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
                    }

                    return $receipt;
                }
            }

            if ($receiptOnly) {
                return null;
            }

            $attempt->assertAdmittedAt($this->clock->now());
            $material = $admitted->getAdmittedMaterial($this->clock->now());
            $invocation = $this->decipher->materialize($material, $issuance);
            if ($invocation->getIssuance()->toArray() !== $issuance->toArray()) {
                throw new AgentDeliveryFailedException(AgentDeliveryFailure::CORRUPT_MATERIAL);
            }

            $attempt->assertAdmittedAt($this->clock->now());
            $receipt = $this->sink->stage($invocation);
            unset($invocation);
            if (!$this->sink->verify($receipt, $issuance)) {
                throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
            }

            return $receipt;
        } catch (AgentDeliveryFailedException $failure) {
            return $failure->getReason();
        } catch (Throwable) {
            // Unclassified faults retain bounded original material, never imply success or trigger new issuance.
        }

        return AgentDeliveryFailure::TEMPORARY;
    }

    /**
     * Executes one stage while preserving uncertainty when the callback completed but commit did not confirm
     *
     * @param callable(): AgentCredentialOperation $operation
     */
    private function transact(#[SensitiveParameter] callable $operation): AgentCredentialOperation
    {
        $callbackCompleted = false;
        if ($this->unitOfWork->isClosed()) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }

        try {
            /** @var AgentCredentialOperation $result */
            $result = $this->unitOfWork->commitTransactional(
                static function () use ($operation, &$callbackCompleted): AgentCredentialOperation {
                    $result = $operation();
                    $callbackCompleted = true;

                    return $result;
                }
            );

            return $result;
        } catch (Throwable $throwable) {
            if ($callbackCompleted) {
                throw new AgentDeliveryCommitUncertainException();
            }

            throw $throwable;
        }
    }

    /**
     * Reconciles recorded completion without recreating a lost sink entry or rewriting historical delivery
     */
    private function reconcileRecordedResult(AgentCredentialOperation $operation): AgentDeliveryResult
    {
        $result = $this->recordedResult($operation);
        if ($result !== AgentDeliveryResult::DELIVERED) {
            return $result;
        }

        $receipt = $operation->getReceipt();
        if ($receipt === null || !$this->sink->verify($receipt, $operation->getIssuance())) {
            return AgentDeliveryResult::RECONCILIATION_REQUIRED;
        }

        return $result;
    }

    /**
     * Returns only confirmed durable disposition without treating missing material as success
     */
    private function recordedResult(AgentCredentialOperation $operation): AgentDeliveryResult
    {
        return match ($operation->getStatus()->getDeliveryDisposition()) {
            AgentDeliveryDisposition::DELIVERED => AgentDeliveryResult::DELIVERED,
            AgentDeliveryDisposition::RETIRED => AgentDeliveryResult::RETIRED,
            AgentDeliveryDisposition::EXPIRED => AgentDeliveryResult::EXPIRED,
            AgentDeliveryDisposition::TERMINAL => AgentDeliveryResult::TERMINAL,
            AgentDeliveryDisposition::RETRYABLE => AgentDeliveryResult::RETRYABLE,
            default => AgentDeliveryResult::REJECTED
        };
    }
}
