<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use LogicException;
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
        private AgentRepository $agentRepository,
        private AgentCredentialOperationRepository $operationRepository,
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
                $authority = $this->authorize($original);
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
                $this->replaceDelivery($original, $replacement, $agent);

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
                $authority = $this->authorize($original);
                $attempt = $admitted->requireAttempt();
                $replacement = $original->finishDelivery(
                    $attempt,
                    $authority,
                    $outcome,
                    $this->clock->now(),
                    $admitted->getStateRevision()
                );
                $this->replaceDelivery($original, $replacement, $agent);

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

                if (
                    in_array($throwable->getReason(), [
                    AgentOperationFailure::UNAUTHORIZED,
                    AgentOperationFailure::CONFLICT,
                    AgentOperationFailure::UNSUPPORTED_VERSION
                    ], true)
                ) {
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
            $authority = $this->authorize($original);
            $replacement = $original->admitDelivery(
                $claimed->requireAttempt(),
                $authority,
                $this->clock->now(),
                $claimed->getStateRevision()
            );
            $this->replaceDelivery($original, $replacement, $agent);

            return $replacement;
        });
    }

    /**
     * Creates current authority bound to the cohort held through this transaction
     */
    private function authorize(AgentCredentialOperation $operation): AgentDeliveryAuthority
    {
        try {
            $authority = $this->deliveryAuthorization->authorize($operation->getIssuance(), $this->clock->now());
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            if (
                !in_array($agentOperationRejectedException->getReason(), [
                AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::UNAVAILABLE
                ], true)
            ) {
                throw new LogicException('The delivery authorization contract failed.');
            }

            throw $agentOperationRejectedException;
        }

        return $this->operationContract()->bindAuthority($authority);
    }

    /**
     * Retrieves exact retained correlation only after current scope authorization
     */
    private function load(
        AgentOperationKey $key,
        AgentCredentialDestination $destination,
        AgentDeliveryId $deliveryId
    ): AgentCredentialOperation {
        try {
            $this->scopeAuthorization->authorize($key->getScope(), $destination);
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            if (
                !in_array($agentOperationRejectedException->getReason(), [
                AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::UNAVAILABLE
                ], true)
            ) {
                throw new LogicException('The scope authorization contract failed.');
            }

            throw $agentOperationRejectedException;
        }

        try {
            $operation = $this->operationRepository->getByKey($key);
        } catch (AgentOperationRejectedException) {
            // A failed lookup supplies neither caller refusal nor claim contention.
            throw new LogicException('The operation lookup failed.');
        }

        if ($operation !== null && $operation->getIssuance()->getKey()->toString() !== $key->toString()) {
            throw new LogicException('The operation lookup identity is inconsistent.');
        }

        if ($operation === null || !$operation->getIssuance()->getDeliveryId()->equals($deliveryId)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        AgentOperationView::fromOperation($operation)->assertReadable($key, $destination);

        return $operation;
    }

    /**
     * Validates the authoritative Agent whose state the repository must fence through the delivery write
     */
    private function currentAgent(AgentCredentialOperation $operation): Agent
    {
        try {
            $agent = $this->agentRepository->getById($operation->getIssuance()->getAgentId());
        } catch (AgentOperationRejectedException) {
            throw new LogicException('The Agent lookup failed.');
        }

        if ($agent === null) {
            throw new AgentOperationRejectedException(AgentOperationFailure::CONFLICT);
        }

        if (!$agent->getId()->equals($operation->getIssuance()->getAgentId())) {
            throw new LogicException('The Agent lookup identity is inconsistent.');
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
        $this->operationContract()->assertCompatible();
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
            try {
                $invocation = $this->decipher->materialize($material, $issuance);
            } catch (AgentDeliveryFailedException $failure) {
                if (
                    !in_array($failure->getReason(), [
                    AgentDeliveryFailure::TEMPORARY,
                    AgentDeliveryFailure::KEY_RETIRED,
                    AgentDeliveryFailure::CORRUPT_MATERIAL
                    ], true)
                ) {
                    throw new LogicException('The delivery decipher contract failed.');
                }

                return $failure->getReason();
            }

            if ($invocation->getIssuance()->toArray() !== $issuance->toArray()) {
                throw new LogicException('The delivery invocation binding is inconsistent.');
            }

            $attempt->assertAdmittedAt($this->clock->now());
            $receipt = $this->sink->stage($invocation);
            unset($invocation);
            if (!$this->sink->verify($receipt, $issuance)) {
                throw new AgentDeliveryFailedException(AgentDeliveryFailure::INVALID_RECEIPT);
            }

            return $receipt;
        } catch (AgentDeliveryFailedException $failure) {
            if (
                !in_array($failure->getReason(), [
                AgentDeliveryFailure::TEMPORARY,
                AgentDeliveryFailure::UNSUPPORTED_SINK,
                AgentDeliveryFailure::INVALID_RECEIPT
                ], true)
            ) {
                throw new LogicException('The protected sink contract failed.');
            }

            return $failure->getReason();
        } catch (LogicException $failure) {
            throw $failure;
        } catch (Throwable) {
            // Unclassified provider faults retain bounded material, never imply success or trigger new issuance.
        }

        return AgentDeliveryFailure::TEMPORARY;
    }

    /**
     * Retrieves cohort evidence without treating a failed capability lookup as delivery refusal
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
     * Persists a successor with only the expected-state writer's deliberate refusal meanings
     */
    private function replaceDelivery(
        #[SensitiveParameter] AgentCredentialOperation $original,
        #[SensitiveParameter] AgentCredentialOperation $replacement,
        #[SensitiveParameter] Agent $agent
    ): void {
        try {
            $this->operationRepository->replaceDelivery($original, $replacement, $agent);
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            if (
                !in_array($agentOperationRejectedException->getReason(), [
                AgentOperationFailure::UNAUTHORIZED,
                AgentOperationFailure::CONFLICT,
                AgentOperationFailure::CONTENTION,
                AgentOperationFailure::UNAVAILABLE
                ], true)
            ) {
                throw new LogicException('The delivery write contract failed.');
            }

            throw $agentOperationRejectedException;
        }
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
                function () use ($operation, &$callbackCompleted): AgentCredentialOperation {
                    try {
                        $this->operationContract()->assertSameCohort($this->agentRepository->getOperationContract());
                    } catch (AgentOperationRejectedException) {
                        throw new LogicException('The delivery cohort is unavailable.');
                    }

                    $result = $operation();
                    $callbackCompleted = true;

                    return $result;
                }
            );

            return $result;
        } catch (Throwable $throwable) {
            /** @var bool $callbackCompleted Updated by the owned callback before commit acknowledgement */
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
        return match ($operation->getDeliveryDisposition()) {
            AgentDeliveryDisposition::DELIVERED => AgentDeliveryResult::DELIVERED,
            AgentDeliveryDisposition::RETIRED => AgentDeliveryResult::RETIRED,
            AgentDeliveryDisposition::EXPIRED => AgentDeliveryResult::EXPIRED,
            AgentDeliveryDisposition::TERMINAL => AgentDeliveryResult::TERMINAL,
            AgentDeliveryDisposition::RETRYABLE => AgentDeliveryResult::RETRYABLE,
            default => AgentDeliveryResult::REJECTED
        };
    }
}
