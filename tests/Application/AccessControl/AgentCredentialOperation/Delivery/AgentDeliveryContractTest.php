<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentCredentialDeliveryService::class)]
final class AgentDeliveryContractTest extends TestCase
{
    public function test_wrong_lookup_key_is_internal_even_when_its_delivery_id_does_not_match(): void
    {
        $env = new DeliveryEnvironment();
        $other = new DeliveryEnvironment();
        $repository = $this->createMock(AgentCredentialOperationRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->provisioning->operationRepository->getOperationContract()
        );
        $repository->method('getByKey')->willReturn($other->operation());
        $repository->expects(self::never())->method('replaceDelivery');
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, $repository));
        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
    }

    public function test_wrong_agent_identity_is_internal_not_a_stale_credential_refusal(): void
    {
        $env = new DeliveryEnvironment();
        $other = new DeliveryEnvironment();
        $repository = $this->createMock(AgentRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->provisioning->agentRepository->getOperationContract()
        );
        $repository->method('getById')->willReturn($other->provisioning->agentRepository->all()[0]);
        $repository->expects(self::never())->method('replace');
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, agentRepository: $repository));
        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(0, $env->decipher->calls);
    }

    /** @return iterable<string, array{AgentOperationFailure}> */
    public static function invalidReadReasons(): iterable
    {
        foreach (AgentOperationFailure::cases() as $reason) {
            if ($reason !== AgentOperationFailure::UNAVAILABLE) {
                yield $reason->value => [$reason];
            }
        }
    }

    #[DataProvider('invalidReadReasons')]
    public function test_failed_lookup_cannot_fabricate_business_rejection_or_deferral(
        AgentOperationFailure $reason
    ): void {
        $env = new DeliveryEnvironment();
        $repository = $this->createStub(AgentCredentialOperationRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->provisioning->operationRepository->getOperationContract()
        );
        $repository->method('getByKey')->willThrowException(new AgentOperationRejectedException($reason));
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, $repository));
        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(0, $env->sink->calls);
    }

    public function test_decipher_cannot_report_a_sink_receipt_failure_and_destroy_original_material(): void
    {
        foreach ([AgentDeliveryFailure::INVALID_RECEIPT, AgentDeliveryFailure::UNSUPPORTED_SINK] as $reason) {
            $env = new DeliveryEnvironment();
            $material = $env->operation()->getMaterial();
            $env->decipher->failure = $reason;
            self::assertSame(AgentDeliveryResult::UNAVAILABLE, $env->deliver());
            self::assertSame($material, $env->operation()->getMaterial());
            self::assertSame(2, $env->operation()->getStateRevision());
            self::assertNull($env->operation()->getDeliveryFailure());
            self::assertSame(0, $env->sink->calls);
        }
    }

    /** @return iterable<string, array{string, AgentOperationFailure, AgentDeliveryResult}> */
    public static function boundaryReasons(): iterable
    {
        foreach (['scope', 'authorization', 'agent', 'cohort', 'write'] as $source) {
            foreach (AgentOperationFailure::cases() as $reason) {
                $expected = AgentDeliveryResult::UNAVAILABLE;
                if (
                    in_array($source, ['scope', 'authorization', 'write'], true)
                    && $reason === AgentOperationFailure::UNAUTHORIZED
                ) {
                    $expected = AgentDeliveryResult::REJECTED;
                }

                if ($source === 'write' && $reason === AgentOperationFailure::CONFLICT) {
                    $expected = AgentDeliveryResult::REJECTED;
                } elseif ($source === 'write' && $reason === AgentOperationFailure::CONTENTION) {
                    $expected = AgentDeliveryResult::DEFERRED;
                }

                yield $source.':'.$reason->value => [$source, $reason, $expected];
            }
        }
    }

    #[DataProvider('boundaryReasons')]
    public function test_failure_intent_is_owned_by_its_actual_boundary(
        string $source,
        AgentOperationFailure $reason,
        AgentDeliveryResult $expected
    ): void {
        $env = new DeliveryEnvironment();
        $failure = new AgentOperationRejectedException($reason);
        $scope = $env->provisioning->operationAuthorization;
        $authorization = $env->deliveryAuthorization;
        $agents = $env->provisioning->agentRepository;
        $operations = $env->provisioning->operationRepository;
        if ($source === 'scope') {
            $scope = $this->createStub(AgentOperationAuthorization::class);
            $scope->method('authorize')->willThrowException($failure);
        } elseif ($source === 'authorization') {
            $authorization = $this->createStub(AgentDeliveryAuthorization::class);
            $authorization->method('authorize')->willThrowException($failure);
        } elseif ($source === 'agent') {
            $agents = $this->createStub(AgentRepository::class);
            $agents->method('getOperationContract')->willReturn($operations->getOperationContract());
            $agents->method('getById')->willThrowException($failure);
        } else {
            $operations = $this->createStub(AgentCredentialOperationRepository::class);
            if ($source === 'cohort') {
                $operations->method('getOperationContract')->willThrowException($failure);
            } else {
                $operations->method('getOperationContract')->willReturn(
                    $env->provisioning->operationRepository->getOperationContract()
                );
                $operations->method('getByKey')->willReturn($env->operation());
                $operations->method('replaceDelivery')->willThrowException($failure);
            }
        }

        self::assertSame($expected, new AgentCredentialDeliveryService(
            $agents,
            $operations,
            $scope,
            $authorization,
            $env->decipher,
            $env->sink,
            $env->clock,
            $env->transaction
        )->deliver($env->issuance->getKey(), $env->issuance->getDestination(), $env->issuance->getDeliveryId()));
        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(0, $env->sink->calls);
    }

    public function test_sink_does_not_classify_delivery_key_loss_or_cipher_integrity(): void
    {
        foreach ([AgentDeliveryFailure::KEY_RETIRED, AgentDeliveryFailure::CORRUPT_MATERIAL] as $reason) {
            $env = new DeliveryEnvironment();
            $material = $env->operation()->getMaterial();
            $sink = $this->createStub(AgentCredentialSink::class);
            $sink->method('stage')->willThrowException(new AgentDeliveryFailedException($reason));
            self::assertSame(AgentDeliveryResult::UNAVAILABLE, $env->service(sink: $sink)->deliver(
                $env->issuance->getKey(),
                $env->issuance->getDestination(),
                $env->issuance->getDeliveryId()
            ));
            self::assertSame($material, $env->operation()->getMaterial());
            self::assertNull($env->operation()->getDeliveryFailure());
        }
    }

    /** @return iterable<string, array{string, AgentDeliveryFailure|'unclassified'|'logic'|'foreign'}> */
    public static function providerFailures(): iterable
    {
        foreach (['support', 'lookup', 'stage', 'verify', 'decipher'] as $source) {
            foreach ([...AgentDeliveryFailure::cases(), 'unclassified', 'logic', 'foreign'] as $failure) {
                yield $source.':'.($failure instanceof AgentDeliveryFailure ? $failure->value : $failure)
                    => [$source, $failure];
            }
        }
    }

    /** @param AgentDeliveryFailure|'unclassified'|'logic'|'foreign' $failure */
    #[DataProvider('providerFailures')]
    public function test_actual_provider_failure_keeps_its_owned_classification(
        string $source,
        AgentDeliveryFailure|string $failure
    ): void {
        $env = new DeliveryEnvironment();
        $material = $env->operation()->getMaterial();
        $exception = match ($failure) {
            'unclassified' => new RuntimeException('Synthetic provider failure.'),
            'logic' => new LogicException('Synthetic provider contract failure.'),
            'foreign' => new AgentDeliveryCommitUncertainException(),
            default => new AgentDeliveryFailedException($failure)
        };
        if ($source === 'lookup') {
            $sink = new ReceiptLookupSink($env);
            $sink->beforeLookup = static function () use ($exception): never {
                throw $exception;
            };
        } else {
            $sink = $this->createMock(AgentCredentialSink::class);
            $method = match ($source) {
                'support' => 'assertSupported',
                'verify' => 'verify',
                default => 'stage'
            };
            if ($source !== 'decipher') {
                $sink->expects(self::once())->method($method)->willThrowException($exception);
                if ($source === 'verify') {
                    $sink->method('stage')->willReturn(new AgentDeliveryReceipt('retained-receipt'));
                }
            }

            if ($source !== 'support') {
                $sink->expects(self::once())->method('assertSupported');
            }
        }

        $decipher = $env->decipher;
        if ($source === 'decipher') {
            $decipher = $this->createStub(AgentDeliveryDecipher::class);
            $decipher->method('materialize')->willThrowException($exception);
        }

        $expected = AgentDeliveryResult::UNAVAILABLE;
        $ownedReasons = [
            AgentDeliveryFailure::TEMPORARY,
            AgentDeliveryFailure::UNSUPPORTED_SINK,
            AgentDeliveryFailure::INVALID_RECEIPT
        ];
        if ($source === 'decipher') {
            $ownedReasons = [
                AgentDeliveryFailure::TEMPORARY,
                AgentDeliveryFailure::KEY_RETIRED,
                AgentDeliveryFailure::CORRUPT_MATERIAL
            ];
        }

        if (in_array($failure, ['unclassified', 'foreign'], true) || in_array($failure, $ownedReasons, true)) {
            $expected = AgentDeliveryResult::TERMINAL;
            if (in_array($failure, ['unclassified', 'foreign'], true) || $failure === AgentDeliveryFailure::TEMPORARY) {
                $expected = AgentDeliveryResult::RETRYABLE;
            }
        }

        self::assertSame($expected, new AgentCredentialDeliveryService(
            $env->provisioning->agentRepository,
            $env->provisioning->operationRepository,
            $env->provisioning->operationAuthorization,
            $env->deliveryAuthorization,
            $decipher,
            $sink,
            $env->clock,
            $env->transaction
        )->deliver($env->issuance->getKey(), $env->issuance->getDestination(), $env->issuance->getDeliveryId()));
        if ($expected === AgentDeliveryResult::UNAVAILABLE) {
            self::assertSame(2, $env->operation()->getStateRevision());
            self::assertNull($env->operation()->getDeliveryFailure());
        } else {
            self::assertSame(3, $env->operation()->getStateRevision());
            self::assertSame(
                in_array($failure, ['unclassified', 'foreign'], true) ? AgentDeliveryFailure::TEMPORARY : $failure,
                $env->operation()->getDeliveryFailure()
            );
        }

        if ($expected === AgentDeliveryResult::TERMINAL) {
            self::assertNull($env->operation()->getMaterial());
        } else {
            self::assertSame($material, $env->operation()->getMaterial());
        }

        self::assertNull($env->operation()->getReceipt());
    }

    /** @return iterable<string, array{int, ?AgentDeliveryFailure}> */
    public static function invocationClockFailures(): iterable
    {
        foreach (range(1, 5) as $sample) {
            foreach ([...AgentDeliveryFailure::cases(), null] as $reason) {
                yield $sample.':'.($reason->value ?? 'unclassified') => [$sample, $reason];
            }
        }
    }

    #[DataProvider('invocationClockFailures')]
    public function test_invocation_clock_fault_does_not_supply_provider_failure_intent(
        int $sample,
        ?AgentDeliveryFailure $reason
    ): void {
        $env = new DeliveryEnvironment();
        $before = $env->operation();
        $agents = $env->provisioning->agentRepository->all();
        $audit = $env->provisioning->auditEvidenceRepository->all();
        $clock = $this->createStub(Clock::class);
        $samples = 0;
        $clock->method('now')->willReturnCallback(static function () use (
            $env,
            $sample,
            $reason,
            &$samples
        ): DateTimeImmutable {
            if (
                $env->transaction->commits === 2 && !$env->provisioning->transaction->transactionActive
                && ++$samples === $sample
            ) {
                if ($reason !== null) {
                    throw new AgentDeliveryFailedException($reason);
                }

                throw new RuntimeException('Synthetic clock failure.');
            }

            return $env->clock->now();
        });
        $issuance = $env->issuance;
        $service = new AgentCredentialDeliveryService(
            $env->provisioning->agentRepository,
            $env->provisioning->operationRepository,
            $env->provisioning->operationAuthorization,
            $env->deliveryAuthorization,
            $env->decipher,
            new ReceiptLookupSink($env),
            $clock,
            $env->transaction
        );
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $service->deliver(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId()
        ));
        self::assertSame($sample, $samples);
        self::assertSame($before->getMaterial(), $env->operation()->getMaterial());
        self::assertSame($before->getIssuance(), $env->operation()->getIssuance());
        self::assertNull($env->operation()->getDeliveryFailure());
        self::assertNull($env->operation()->getReceipt());
        self::assertSame(2, $env->operation()->getStateRevision());
        self::assertSame(2, $env->transaction->commits);
        self::assertNotNull($env->operation()->requireAttempt()->getAuthority());
        self::assertSame($agents, $env->provisioning->agentRepository->all());
        self::assertSame($audit, $env->provisioning->auditEvidenceRepository->all());
        self::assertSame($sample === 5 ? 1 : 0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);

        $env->clock->advance(61);
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame($issuance, $env->operation()->getIssuance());
        self::assertSame(1, $env->sink->calls);
    }

    private function deliver(
        DeliveryEnvironment $env,
        ?AgentCredentialOperationRepository $operationRepository = null,
        ?AgentRepository $agentRepository = null
    ): AgentDeliveryResult {
        return new AgentCredentialDeliveryService(
            $agentRepository ?? $env->provisioning->agentRepository,
            $operationRepository ?? $env->provisioning->operationRepository,
            $env->provisioning->operationAuthorization,
            $env->deliveryAuthorization,
            $env->decipher,
            $env->sink,
            $env->clock,
            $env->transaction
        )->deliver($env->issuance->getKey(), $env->issuance->getDestination(), $env->issuance->getDeliveryId());
    }
}
