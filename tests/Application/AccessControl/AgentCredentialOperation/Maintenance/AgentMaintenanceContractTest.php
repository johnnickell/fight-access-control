<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentDeliveryMaintenanceService::class)]
final class AgentMaintenanceContractTest extends TestCase
{
    public function test_wrong_lookup_key_is_internal_without_touching_another_operations_material(): void
    {
        $env = new MaintenanceEnvironment();
        $other = new MaintenanceEnvironment();
        $repository = $this->createMock(AgentCredentialOperationRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $repository->method('getByKey')->willReturn($other->delivery->operation());
        $repository->expects(self::never())->method('replaceMaintenance');
        $before = $env->delivery->operation();
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $this->rewrap($env, $repository));
        self::assertSame($before, $env->delivery->operation());
        self::assertSame(0, $env->rewraps);
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
    public function test_failed_lookup_is_not_a_maintenance_refusal(AgentOperationFailure $reason): void
    {
        $env = new MaintenanceEnvironment();
        $repository = $this->createStub(AgentCredentialOperationRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $repository->method('getByKey')->willThrowException(new AgentOperationRejectedException($reason));
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $this->rewrap($env, $repository));
        self::assertSame(0, $env->rewraps);
    }

    public function test_rewrapper_cannot_report_receipt_or_sink_failure_as_ordinary_retry(): void
    {
        foreach ([AgentDeliveryFailure::INVALID_RECEIPT, AgentDeliveryFailure::UNSUPPORTED_SINK] as $reason) {
            $env = new MaintenanceEnvironment();
            $before = $env->delivery->operation();
            $env->failure = $reason;
            self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->rewrapOriginal());
            self::assertSame($before, $env->delivery->operation());
            self::assertSame(0, $env->delivery->sink->cleanups);
        }
    }

    public function test_slow_scope_authorization_expiring_before_return_prevents_concealed_lookup(): void
    {
        $env = new MaintenanceEnvironment();
        $env->expiresAt = $env->delivery->clock->now()->modify('+1 second');

        $authorization = $this->createStub(AgentMaintenanceAuthorization::class);
        $authorization->method('authorize')->willReturnCallback(static function (
            AgentOperationScope $scope,
            AgentCredentialDestination $destination,
            ?AgentIssuance $issuance,
            ?AgentDeliveryKeyVersion $target,
            DateTimeImmutable $now
        ) use ($env): AgentDeliveryAuthority {
            $authority = $env->authorize($scope, $destination, $issuance, $target, $now);
            $env->delivery->clock->advance(1);

            return $authority;
        });
        $repository = $this->createMock(AgentCredentialOperationRepository::class);
        $repository->method('getOperationContract')->willReturn(
            $env->delivery->provisioning->operationRepository->getOperationContract()
        );
        $repository->expects(self::never())->method('getByKey');
        $issuance = $env->delivery->issuance;
        self::assertSame(AgentMaintenanceResult::REJECTED, new AgentDeliveryMaintenanceService(
            $repository,
            $authorization,
            $env,
            $env->delivery->sink,
            $env->delivery->clock,
            $env->delivery->transaction
        )->expire($issuance->getKey(), $issuance->getDestination(), $issuance->getDeliveryId()));
        self::assertSame(0, $env->rewraps);
        self::assertSame(0, $env->delivery->sink->cleanups);
    }

    /** @return iterable<string, array{string, AgentOperationFailure, AgentMaintenanceResult}> */
    public static function boundaryReasons(): iterable
    {
        foreach (['scope', 'target', 'cohort', 'write'] as $source) {
            foreach (AgentOperationFailure::cases() as $reason) {
                $expected = AgentMaintenanceResult::UNAVAILABLE;
                if (
                    $source !== 'cohort' && in_array($reason, [
                    AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::CONFLICT
                    ], true)
                ) {
                    $expected = AgentMaintenanceResult::REJECTED;
                } elseif ($source === 'write' && $reason === AgentOperationFailure::CONTENTION) {
                    $expected = AgentMaintenanceResult::REJECTED;
                }

                yield $source.':'.$reason->value => [$source, $reason, $expected];
            }
        }
    }

    #[DataProvider('boundaryReasons')]
    public function test_only_source_qualified_reasons_describe_maintenance(
        string $source,
        AgentOperationFailure $reason,
        AgentMaintenanceResult $expected
    ): void {
        $env = new MaintenanceEnvironment();
        $failure = new AgentOperationRejectedException($reason);
        $repository = $this->createStub(AgentCredentialOperationRepository::class);
        if ($source === 'cohort') {
            $repository->method('getOperationContract')->willThrowException($failure);
        } else {
            $repository->method('getOperationContract')->willReturn(
                $env->delivery->provisioning->operationRepository->getOperationContract()
            );
            $repository->method('getByKey')->willReturn($env->delivery->operation());
            $repository->method('replaceMaintenance')->willThrowException($failure);
        }

        $authorization = $this->createStub(AgentMaintenanceAuthorization::class);
        $authorization->method('authorize')->willReturnCallback(static function (
            AgentOperationScope $scope,
            AgentCredentialDestination $destination,
            ?AgentIssuance $issuance,
            ?AgentDeliveryKeyVersion $target,
            DateTimeImmutable $now
        ) use (
            $env,
            $source,
            $failure
): AgentDeliveryAuthority {
            if (($source === 'scope' && $issuance === null) || ($source === 'target' && $issuance !== null)) {
                throw $failure;
            }

            return $env->authorize($scope, $destination, $issuance, $target, $now);
        });
        $before = $env->delivery->operation();
        self::assertSame($expected, $this->rewrap($env, $repository, $authorization));
        self::assertSame($before, $env->delivery->operation());
        self::assertSame(0, $env->delivery->sink->cleanups);
    }

    public function test_cleanup_failures_distinguish_retry_binding_rejection_and_internal_contract_failure(): void
    {
        foreach (AgentDeliveryFailure::cases() as $reason) {
            $env = new MaintenanceEnvironment();
            $env->delivery->revoke();
            $env->delivery->clock->advance(172800);
            $cleanup = $this->createStub(AgentCredentialCleanup::class);
            $cleanup->method('remove')->willThrowException(new AgentDeliveryFailedException($reason));
            $issuance = $env->delivery->issuance;
            $expected = match ($reason) {
                AgentDeliveryFailure::TEMPORARY,
                AgentDeliveryFailure::UNSUPPORTED_SINK => AgentMaintenanceResult::RETRYABLE,
                AgentDeliveryFailure::INVALID_RECEIPT => AgentMaintenanceResult::REJECTED,
                default => AgentMaintenanceResult::UNAVAILABLE
            };
            self::assertSame($expected, new AgentDeliveryMaintenanceService(
                $env->delivery->provisioning->operationRepository,
                $env,
                $env,
                $cleanup,
                $env->delivery->clock,
                $env->delivery->transaction
            )->cleanup($issuance->getKey(), $issuance->getDestination(), $issuance->getDeliveryId()));
            self::assertFalse($env->delivery->operation()->isSinkCleaned());
            self::assertSame(1, $env->delivery->provisioning->generations);
        }
    }

    public function test_key_and_cleanup_providers_do_not_supply_operation_authorization_intent(): void
    {
        foreach (['rewrap', 'cleanup'] as $operation) {
            $env = new MaintenanceEnvironment();
            $fault = static function (): void {
                throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
            };
            if ($operation === 'rewrap') {
                $env->afterRewrap = $fault;
                $result = $env->rewrapOriginal();
                self::assertSame('test-key-v1', $env->delivery->operation()->getMaterial()?->getKeyVersion());
            } else {
                $env->delivery->revoke();
                $env->delivery->clock->advance(172800);
                $env->delivery->sink->afterCleanup = $fault;
                $result = $env->cleanup();
                self::assertFalse($env->delivery->operation()->isSinkCleaned());
            }

            self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $result);
        }
    }

    private function rewrap(
        MaintenanceEnvironment $env,
        AgentCredentialOperationRepository $operationRepository,
        ?AgentMaintenanceAuthorization $authorization = null
    ): AgentMaintenanceResult {
        $issuance = $env->delivery->issuance;

        return new AgentDeliveryMaintenanceService(
            $operationRepository,
            $authorization ?? $env,
            $env,
            $env->delivery->sink,
            $env->delivery->clock,
            $env->delivery->transaction
        )->rewrap(
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance->getDeliveryId(),
            new AgentDeliveryKeyVersion('test-key-v2')
        );
    }
}
