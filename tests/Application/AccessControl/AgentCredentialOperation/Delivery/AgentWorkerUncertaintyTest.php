<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryRewrapper;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryCommitUncertainException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
final class AgentWorkerUncertaintyTest extends TestCase
{
    /** @return iterable<string, array{?Throwable, bool}> */
    public static function recordedVerification(): iterable
    {
        foreach (AgentWorkerFailureSourceTest::faults() as $name => [$failure]) {
            yield $name => [$failure, true];
        }

        yield 'valid receipt' => [null, true];
        yield 'lost entry' => [null, false];
    }

    #[DataProvider('recordedVerification')]
    public function test_recorded_verification_read_failure_cannot_supply_refusal_or_commit_intent(
        ?Throwable $failure,
        bool $valid
    ): void {
        $env = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        $before = $env->operation();
        $agents = $env->provisioning->agentRepository->all();
        $audit = $env->provisioning->auditEvidenceRepository->all();
        $commits = $env->transaction->commits;
        $env->sink->validReceipt = $valid;
        if ($failure !== null) {
            $env->sink->beforeVerify = static function () use ($failure): never {
                throw $failure;
            };
        }

        $expected = AgentDeliveryResult::DELIVERED;
        if ($failure !== null) {
            $expected = AgentDeliveryResult::UNAVAILABLE;
        } elseif (!$valid) {
            $expected = AgentDeliveryResult::RECONCILIATION_REQUIRED;
        }

        self::assertSame($expected, $env->deliver());
        self::assertSame($before, $env->operation());
        self::assertSame($agents, $env->provisioning->agentRepository->all());
        self::assertSame($audit, $env->provisioning->auditEvidenceRepository->all());
        self::assertSame($commits + 1, $env->transaction->commits);
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->decipher->calls);
        $env->sink->beforeVerify = null;
        $env->sink->validReceipt = true;
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame($before, $env->operation());
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->provisioning->generations);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function nonCommitSources(): iterable
    {
        foreach (['delivery', 'rewrap', 'expire', 'cleanup'] as $operation) {
            $sources = ['operation cohort', 'operation read', 'authorization'];
            if ($operation === 'delivery') {
                $sources = [...$sources, 'agent cohort', 'agent read', 'scope', 'delivery write'];
            } else {
                $sources[] = 'maintenance write';
                if ($operation === 'rewrap') {
                    $sources[] = 'rewrapper';
                } elseif ($operation === 'cleanup') {
                    $sources[] = 'cleanup';
                }
            }

            foreach ($sources as $source) {
                foreach ([false, true] as $foreignUncertainty) {
                    yield $operation.':'.$source.':'.($foreignUncertainty ? 'foreign' : 'runtime')
                        => [$operation, $source, $foreignUncertainty];
                }
            }
        }
    }

    #[DataProvider('nonCommitSources')]
    public function test_every_non_commit_collaborator_call_contains_foreign_uncertainty_and_recovers_original_tuple(
        string $operation,
        string $source,
        bool $foreignUncertainty
    ): void {
        $failure = new RuntimeException('Synthetic non-commit source failure.');
        if ($foreignUncertainty) {
            $failure = new AgentDeliveryCommitUncertainException();
        }

        $calls = 0;
        $count = static function (string $actual) use ($source, &$calls): void {
            if ($actual === $source) {
                ++$calls;
            }
        };
        $control = $this->environment($operation);
        $this->invoke($control, $operation, $count);
        self::assertGreaterThan(0, $calls);
        foreach (range(1, $calls) as $sample) {
            $env = $this->environment($operation);
            $delivery = $env->delivery;
            $before = $delivery->operation();
            $agents = $delivery->provisioning->agentRepository->all();
            $audit = $delivery->provisioning->auditEvidenceRepository->all();
            $seen = 0;
            $fault = static function (string $actual) use ($source, $sample, $failure, &$seen): void {
                if ($actual === $source && ++$seen === $sample) {
                    throw $failure;
                }
            };
            self::assertSame('unavailable', $this->invoke($env, $operation, $fault)->value, $source.':'.$sample);
            self::assertSame($sample, $seen);
            self::assertSame($before->getIssuance(), $delivery->operation()->getIssuance());
            self::assertSame($before->getMaterial(), $delivery->operation()->getMaterial());
            self::assertSame($agents, $delivery->provisioning->agentRepository->all());
            self::assertSame($audit, $delivery->provisioning->auditEvidenceRepository->all());
            self::assertNull($delivery->operation()->getDeliveryFailure());
            if ($operation !== 'delivery') {
                self::assertSame($before, $delivery->operation());
                self::assertFalse($delivery->operation()->isSinkCleaned());
            } else {
                self::assertNull($delivery->operation()->getReceipt());
                self::assertLessThanOrEqual(2, $delivery->operation()->getStateRevision());
                self::assertSame(
                    $delivery->sink->calls === 1,
                    $delivery->sink->receiptFor($delivery->issuance) !== null
                );
                $delivery->clock->advance(61);
            }

            if ($source === 'cleanup') {
                self::assertNull($delivery->sink->stagedBytes($delivery->issuance));
                self::assertSame(1, $delivery->sink->cleanups);
                self::assertFalse($delivery->operation()->isSinkCleaned());
            }

            self::assertSame(match ($operation) {
                'delivery' => 'delivered',
                'rewrap' => 'rewrapped',
                'expire' => 'expired',
                default => 'cleaned'
            }, $this->invoke($env, $operation, static function (): void {
            })->value);
            self::assertSame($before->getIssuance(), $delivery->operation()->getIssuance());
            self::assertSame(1, $delivery->provisioning->generations);
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

    /** @param callable(string): void $hit */
    private function invoke(
        MaintenanceEnvironment $env,
        string $operation,
        callable $hit
    ): AgentDeliveryResult|AgentMaintenanceResult {
        $delivery = $env->delivery;
        $operations = $this->createStub(AgentCredentialOperationRepository::class);
        $agents = $this->createStub(AgentRepository::class);
        $scope = $this->createStub(AgentOperationAuthorization::class);
        $authorization = $this->createStub(AgentDeliveryAuthorization::class);
        $maintenance = $this->createStub(AgentMaintenanceAuthorization::class);
        $rewrapper = $this->createStub(AgentDeliveryRewrapper::class);
        $cleanup = $this->createStub(AgentCredentialCleanup::class);
        foreach (
            [
            [$operations, $delivery->provisioning->operationRepository, 'getOperationContract', 'operation cohort'],
            [$operations, $delivery->provisioning->operationRepository, 'getByKey', 'operation read'],
            [$operations, $delivery->provisioning->operationRepository, 'replaceDelivery', 'delivery write'],
            [$operations, $delivery->provisioning->operationRepository, 'replaceMaintenance', 'maintenance write'],
            [$agents, $delivery->provisioning->agentRepository, 'getOperationContract', 'agent cohort'],
            [$agents, $delivery->provisioning->agentRepository, 'getById', 'agent read'],
            [$scope, $delivery->provisioning->operationAuthorization, 'authorize', 'scope'],
            [$authorization, $delivery->deliveryAuthorization, 'authorize', 'authorization'],
            [$maintenance, $env, 'authorize', 'authorization'],
            [$rewrapper, $env, 'rewrap', 'rewrapper'],
            [$cleanup, $delivery->sink, 'remove', 'cleanup']
            ] as [$stub, $delegate, $method, $source]
        ) {
            $stub->method($method)->willReturnCallback(static function (...$arguments) use (
                $delegate,
                $method,
                $source,
                $hit
            ): mixed {
                // Cleanup can have physically succeeded without starting a database acknowledgement.
                if ($source === 'cleanup') {
                    $delegate->$method(...$arguments);
                    $hit($source);

                    return null;
                }

                $hit($source);

                return $delegate->$method(...$arguments);
            });
        }

        $unitOfWork = $this->createStub(TransactionalUnitOfWork::class);
        $unitOfWork->method('isClosed')->willReturn(false);
        $unitOfWork->method('commitTransactional')->willReturnCallback(static function (callable $callback) use (
            $delivery
        ): mixed {
            $primary = null;
            try {
                return $delivery->transaction->commitTransactional(static function () use (
                    $callback,
                    &$primary
                ): mixed {
                    try {
                        return $callback();
                    } catch (Throwable $throwable) {
                        $primary = $throwable;
                        throw $throwable;
                    }
                });
            } catch (Throwable $throwable) {
                self::assertSame($primary, $throwable);
                // The worker must retain the callback primary even if rollback mechanics replace it.
                throw new RuntimeException('Synthetic rollback mechanics failure.');
            }
        });
        $issuance = $delivery->issuance;
        $arguments = [$issuance->getKey(), $issuance->getDestination(), $issuance->getDeliveryId()];
        if ($operation === 'delivery') {
            return new AgentCredentialDeliveryService(
                $agents,
                $operations,
                $scope,
                $authorization,
                $delivery->decipher,
                $delivery->sink,
                $delivery->clock,
                $unitOfWork
            )->deliver(...$arguments);
        }

        $service = new AgentDeliveryMaintenanceService(
            $operations,
            $maintenance,
            $rewrapper,
            $cleanup,
            $delivery->clock,
            $unitOfWork
        );

        return match ($operation) {
            'rewrap' => $service->rewrap(...[...$arguments, new AgentDeliveryKeyVersion('test-key-v2')]),
            'expire' => $service->expire(...$arguments),
            default => $service->cleanup(...$arguments)
        };
    }
}
