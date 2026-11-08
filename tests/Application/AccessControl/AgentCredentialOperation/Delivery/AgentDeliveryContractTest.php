<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
