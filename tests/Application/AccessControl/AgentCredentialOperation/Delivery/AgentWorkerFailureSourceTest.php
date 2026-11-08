<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
final class AgentWorkerFailureSourceTest extends TestCase
{
    /** @return iterable<string, array{Throwable}> */
    public static function faults(): iterable
    {
        foreach (AgentOperationFailure::cases() as $reason) {
            yield 'operation '.$reason->value => [new AgentOperationRejectedException($reason)];
        }

        foreach (AgentDeliveryFailure::cases() as $reason) {
            yield 'delivery '.$reason->value => [new AgentDeliveryFailedException($reason)];
        }

        yield 'uncertain' => [new AgentDeliveryCommitUncertainException()];
        yield 'runtime' => [new RuntimeException('Synthetic mechanics fault.')];
        yield 'logic' => [new LogicException('Synthetic contract fault.')];
    }

    /** @return iterable<string, array{string, int, Throwable}> */
    public static function clockFaults(): iterable
    {
        foreach (['delivery' => 12, 'rewrap' => 8, 'expire' => 6, 'cleanup' => 14] as $operation => $samples) {
            foreach (range(1, $samples) as $sample) {
                foreach (self::faults() as $name => [$failure]) {
                    yield $operation.':'.$sample.':'.$name => [$operation, $sample, $failure];
                }
            }
        }
    }

    #[DataProvider('clockFaults')]
    public function test_every_owned_time_sample_contains_faults_without_borrowing_intent(
        string $operation,
        int $sample,
        Throwable $failure
    ): void {
        $env = $this->environment($operation);
        $before = $env->delivery->operation();
        $agents = $env->delivery->provisioning->agentRepository->all();
        $audit = $env->delivery->provisioning->auditEvidenceRepository->all();
        $clock = $this->createStub(Clock::class);
        $samples = 0;
        $clock->method('now')->willReturnCallback(static function () use (
            $env,
            $sample,
            $failure,
            &$samples
        ): DateTimeImmutable {
            if (++$samples === $sample) {
                throw $failure;
            }

            return $env->delivery->clock->now();
        });
        self::assertSame('unavailable', $this->invoke($env, $operation, $clock)->value);
        self::assertSame($sample, $samples);
        self::assertSame($before->getMaterial(), $env->delivery->operation()->getMaterial());
        self::assertSame($before->getIssuance(), $env->delivery->operation()->getIssuance());
        self::assertSame($before->getDeliveryFailure(), $env->delivery->operation()->getDeliveryFailure());
        self::assertSame($agents, $env->delivery->provisioning->agentRepository->all());
        self::assertSame($audit, $env->delivery->provisioning->auditEvidenceRepository->all());
        if ($operation === 'delivery') {
            self::assertSame(
                $sample <= 3 ? 0 : ($sample <= 5 ? 1 : 2),
                $env->delivery->operation()->getStateRevision()
            );
            self::assertNull($env->delivery->operation()->getReceipt());
            self::assertSame($sample >= 11 ? 1 : 0, $env->delivery->sink->calls);
        } else {
            self::assertSame($before, $env->delivery->operation());
            self::assertFalse($env->delivery->operation()->isSinkCleaned());
            self::assertSame($operation === 'cleanup' && $sample >= 8 ? 1 : 0, $env->delivery->sink->cleanups);
        }

        $this->assertRecovery($env, $operation);
    }

    /** @return iterable<string, array{string, int, string, Throwable}> */
    public static function mechanicsFaults(): iterable
    {
        foreach (['delivery' => 3, 'rewrap' => 1, 'expire' => 1, 'cleanup' => 2] as $operation => $phases) {
            foreach (range(1, $phases) as $phase) {
                foreach (['status', 'start', 'completed rollback', 'completed commit'] as $source) {
                    foreach (self::faults() as $name => [$failure]) {
                        yield $operation.':'.$phase.':'.$source.':'.$name => [$operation, $phase, $source, $failure];
                    }
                }
            }
        }
    }

    #[DataProvider('mechanicsFaults')]
    public function test_transaction_mechanics_do_not_supply_callback_intent_or_erase_uncertainty(
        string $operation,
        int $phase,
        string $source,
        Throwable $failure
    ): void {
        $env = $this->environment($operation);
        $before = $env->delivery->operation();
        $agents = $env->delivery->provisioning->agentRepository->all();
        $audit = $env->delivery->provisioning->auditEvidenceRepository->all();
        $statusCalls = 0;
        $callbackCalls = 0;
        $unitOfWork = $this->createStub(TransactionalUnitOfWork::class);
        $unitOfWork->method('isClosed')->willReturnCallback(static function () use (
            &$statusCalls,
            $phase,
            $source,
            $failure
        ): bool {
            if (++$statusCalls === $phase && $source === 'status') {
                throw $failure;
            }

            return false;
        });
        $unitOfWork->method('commitTransactional')->willReturnCallback(static function (callable $callback) use (
            $env,
            &$callbackCalls,
            &$statusCalls,
            $phase,
            $source,
            $failure
        ): mixed {
            $fail = $statusCalls === $phase;
            if ($fail && $source === 'start') {
                throw $failure;
            }

            $result = $env->delivery->transaction->commitTransactional(static function () use (
                $callback,
                &$callbackCalls,
                $fail,
                $source,
                $failure
            ): mixed {
                ++$callbackCalls;
                $result = $callback();
                if ($fail && $source === 'completed rollback') {
                    throw $failure;
                }

                return $result;
            });
            if ($fail && $source === 'completed commit') {
                throw $failure;
            }

            return $result;
        });
        $completed = str_starts_with($source, 'completed');
        self::assertSame(
            $completed ? 'indeterminate' : 'unavailable',
            $this->invoke($env, $operation, unitOfWork: $unitOfWork)->value
        );
        self::assertSame($phase - ($completed ? 0 : 1), $callbackCalls);
        self::assertSame($agents, $env->delivery->provisioning->agentRepository->all());
        self::assertSame($audit, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame($before->getIssuance(), $env->delivery->operation()->getIssuance());
        $persisted = $source === 'completed commit';
        if ($operation === 'delivery') {
            self::assertSame($phase - ($persisted ? 0 : 1), $env->delivery->operation()->getStateRevision());
            self::assertSame($phase === 3 ? 1 : 0, $env->delivery->sink->calls);
            self::assertSame($persisted && $phase === 3, $env->delivery->operation()->getReceipt() !== null);
            self::assertSame(
                $persisted && $phase === 3 ? null : $before->getMaterial(),
                $env->delivery->operation()->getMaterial()
            );
        } elseif ($persisted && $operation === 'rewrap') {
            self::assertSame('test-key-v2', $env->delivery->operation()->getMaterial()?->getKeyVersion());
        } elseif ($persisted && $operation === 'expire') {
            self::assertNull($env->delivery->operation()->getMaterial());
        } else {
            self::assertSame(
                $operation === 'cleanup' && $persisted && $phase === 2,
                $env->delivery->operation()->isSinkCleaned()
            );
            if (!$persisted || $operation !== 'cleanup' || $phase === 1) {
                self::assertSame($before, $env->delivery->operation());
            }
        }

        self::assertSame($operation === 'cleanup' && $phase === 2 ? 1 : 0, $env->delivery->sink->cleanups);
        $this->assertRecovery($env, $operation);
    }

    /** @return iterable<string, array{AgentOperationFailure}> */
    public static function callbackRefusals(): iterable
    {
        foreach (
            [AgentOperationFailure::UNAUTHORIZED, AgentOperationFailure::CONFLICT,
            AgentOperationFailure::CONTENTION, AgentOperationFailure::UNAVAILABLE] as $reason
        ) {
            yield $reason->value => [$reason];
        }
    }

    #[DataProvider('callbackRefusals')]
    public function test_owned_writer_refusal_survives_uow_replacement_of_callback_exception(
        AgentOperationFailure $reason
    ): void {
        foreach (['delivery', 'rewrap'] as $operation) {
            $env = $this->environment($operation);
            $before = $env->delivery->operation();
            $failure = new AgentOperationRejectedException($reason);
            $fault = static function () use ($failure): never {
                throw $failure;
            };
            $repository = $env->delivery->provisioning->operationRepository;
            if ($operation === 'delivery') {
                $repository->beforeDeliveryWrite = $fault;
            } else {
                $repository->beforeMaintenanceWrite = $fault;
            }

            $caught = null;
            $unitOfWork = $this->createStub(TransactionalUnitOfWork::class);
            $unitOfWork->method('isClosed')->willReturn(false);
            $unitOfWork->method('commitTransactional')->willReturnCallback(static function (callable $callback) use (
                $env,
                &$caught
            ): mixed {
                try {
                    return $env->delivery->transaction->commitTransactional($callback);
                } catch (Throwable $throwable) {
                    $caught = $throwable;
                    throw new RuntimeException('Synthetic rollback failure.');
                }
            });
            $expected = 'rejected';
            if ($reason === AgentOperationFailure::UNAVAILABLE) {
                $expected = 'unavailable';
            } elseif ($operation === 'delivery' && $reason === AgentOperationFailure::CONTENTION) {
                $expected = 'deferred';
            }

            self::assertSame($expected, $this->invoke($env, $operation, unitOfWork: $unitOfWork)->value);
            self::assertSame($failure, $caught);
            self::assertSame($before, $env->delivery->operation());
            self::assertSame(0, $env->delivery->sink->calls);
            $repository->beforeDeliveryWrite = null;
            $repository->beforeMaintenanceWrite = null;
            $this->assertRecovery($env, $operation);
        }
    }

    private function environment(string $operation): MaintenanceEnvironment
    {
        $env = new MaintenanceEnvironment();
        if ($operation === 'expire') {
            $env->delivery->clock->advance(86400);
        } elseif ($operation === 'cleanup') {
            self::assertSame(AgentDeliveryResult::DELIVERED, $env->delivery->deliver());
            $env->delivery->revoke();
            $env->delivery->clock->advance(172800);
        }

        return $env;
    }

    private function invoke(
        MaintenanceEnvironment $env,
        string $operation,
        ?Clock $clock = null,
        ?TransactionalUnitOfWork $unitOfWork = null
    ): AgentDeliveryResult|AgentMaintenanceResult {
        $delivery = $env->delivery;
        $issuance = $delivery->issuance;
        if ($operation === 'delivery') {
            return new AgentCredentialDeliveryService(
                $delivery->provisioning->agentRepository,
                $delivery->provisioning->operationRepository,
                $delivery->provisioning->operationAuthorization,
                $delivery->deliveryAuthorization,
                $delivery->decipher,
                $delivery->sink,
                $clock ?? $delivery->clock,
                $unitOfWork ?? $delivery->transaction
            )->deliver($issuance->getKey(), $issuance->getDestination(), $issuance->getDeliveryId());
        }

        $service = new AgentDeliveryMaintenanceService(
            $delivery->provisioning->operationRepository,
            $env,
            $env,
            $delivery->sink,
            $clock ?? $delivery->clock,
            $unitOfWork ?? $delivery->transaction
        );
        $arguments = [$issuance->getKey(), $issuance->getDestination(), $issuance->getDeliveryId()];

        return match ($operation) {
            'rewrap' => $service->rewrap(...[...$arguments, new AgentDeliveryKeyVersion('test-key-v2')]),
            'expire' => $service->expire(...$arguments),
            default => $service->cleanup(...$arguments)
        };
    }

    private function assertRecovery(MaintenanceEnvironment $env, string $operation): void
    {
        if ($operation === 'delivery') {
            $env->delivery->clock->advance(61);
        }

        $before = $env->delivery->operation();
        $expected = match ($operation) {
            'delivery' => 'delivered',
            'rewrap' => $before->getMaterial()?->getKeyVersion() === 'test-key-v2' ? 'unchanged' : 'rewrapped',
            'expire' => $before->getMaterial() === null ? 'unchanged' : 'expired',
            default => 'cleaned'
        };
        self::assertSame($expected, $this->invoke($env, $operation)->value);
        self::assertSame(1, $env->delivery->provisioning->generations);
    }
}
