<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRevoked;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\ControlledAgentDeliveryWriter;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\RehydratedAgentFixture;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameterValue;

#[CoversClass(Agent::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentOperationRejectedException::class)]
#[CoversClass(AgentCredentialException::class)]
#[CoversClass(AgentCredentialRevoked::class)]
#[CoversClass(AgentCredentialLifecycleFailed::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentCredentialRetirementTest extends TestCase
{
    public function test_revocation_commits_retirement_and_audit_without_keys_and_preserves_original_resolution(): void
    {
        $environment = $this->issued();
        $agent = $environment->agentRepository->all()[0];
        $original = $this->operation($environment);
        $versions = $environment->agentOperationRepository->versions;
        $environment->eventDispatcher = new InMemoryEventDispatcher(function (object $event) use ($environment): void {
            self::assertFalse($environment->transaction->transactionActive);
            self::assertSame(AgentState::REVOKED, $environment->agentRepository->all()[0]->getState());
            self::assertNull($this->operation($environment)->getMaterial());
            self::assertCount(2, $environment->auditEvidenceRepository->all());
            self::assertInstanceOf(AgentCredentialRevoked::class, $event);
        });

        $this->service($environment)->revoke('maintainer-42', $agent->getId());

        $retired = $this->operation($environment);
        self::assertSame($original->getIssuance(), $retired->getIssuance());
        self::assertSame($original->getCanonicalRequest(), $retired->getCanonicalRequest());
        self::assertSame($original->getCanonicalVersion(), $retired->getCanonicalVersion());
        self::assertSame(AgentCredentialDisposition::REVOKED, $retired->getStatus()->getCredentialDisposition());
        self::assertSame(AgentDeliveryDisposition::RETIRED, $retired->getStatus()->getDeliveryDisposition());
        self::assertSame(1, $retired->getStateRevision());
        self::assertFalse($retired->hasPendingDeliveryAtRevision(0));
        self::assertFalse($retired->hasPendingDeliveryAtRevision(1));
        self::assertSame($versions, $environment->agentOperationRepository->versions);
        $nonces = new InMemoryAgentRequestNonceConsumer($environment->agentRepository, $environment->transaction);
        self::assertFalse($nonces->consume(
            $agent->getId(),
            $agent->getCredentialId(),
            $agent->getCredentialRevision(),
            $agent->getPermissionAssignmentRevision(),
            'fresh-nonce-after-revocation',
            $this->now()->modify('+5 minutes')
        ));
        self::assertNull($nonces->expiresAt());
        self::assertSame('agent.credential_revoked', $environment->auditEvidenceRepository->all()[1]->action());
        self::assertSame(
            $agent->getEncryptedHmacSharedSecretEnvelope(),
            $environment->agentRepository->all()[0]->getEncryptedHmacSharedSecretEnvelope()
        );
        $environment->eventDispatcher = new InMemoryEventDispatcher();
        $resolved = $environment->bus()->dispatchAndRead(new ProvisionAgent($environment->key, $environment->request));
        self::assertSame($original->getIssuance(), $resolved->getIssuance());
        self::assertSame(1, $environment->generations);
        self::assertCount(2, $environment->auditEvidenceRepository->all());
        $safe = serialize([$retired->getStatus()->toArray(), $environment->auditEvidenceRepository->all()]);
        ob_start();
        var_dump($retired);
        $safe .= ob_get_clean();
        self::assertStringNotContainsString('original-test-secret', $safe);
        self::assertStringNotContainsString('auth-envelope:', $safe);
        self::assertStringNotContainsString('ciphertext', $safe);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function controlledStages(): iterable
    {
        foreach (['pending' => 0, 'claimed' => 1, 'admitted' => 2] as $label => $writes) {
            yield $label.' service' => [$writes, false];
            yield $label.' direct repository' => [$writes, true];
        }
    }

    #[DataProvider('controlledStages')]
    public function test_retirement_fences_controlled_claim_admission_and_completion(int $writes, bool $direct): void
    {
        $environment = $this->issued();
        $writer = new ControlledAgentDeliveryWriter($environment);
        $epoch = $environment->operationAuthorization->epoch;
        for ($index = 0; $index < $writes; ++$index) {
            self::assertTrue($writer->advance($this->operation($environment), $epoch));
        }

        $stale = $this->operation($environment);
        $agent = $environment->agentRepository->all()[0];
        $environment->agentOperationRepository->afterRetirement = function () use (
            $environment,
            $writer,
            $stale,
            $epoch
        ): void {
            self::assertTrue($environment->operationAuthorization->locked);
            self::assertFalse($writer->advance($stale, $epoch, complete: true));
        };
        if ($direct) {
            $environment->transaction->commitTransactional(function () use ($environment, $agent): void {
                self::assertTrue($environment->agentRepository->replace($agent, $agent->revoke($this->now())));
                $environment->auditEvidenceRepository->add(
                    AuditEvidence::agentCredentialRevoked('maintainer-42', $agent->getId())
                );
            });
        } else {
            $this->service($environment)->revoke('maintainer-42', $agent->getId());
        }

        self::assertSame($writes + 1, $this->operation($environment)->getStateRevision());
        self::assertFalse($writer->advance($stale, $epoch));
        self::assertFalse($writer->advance($stale, $epoch, complete: true));
        self::assertFalse($writer->advance($this->operation($environment), $epoch));
        self::assertNull($this->operation($environment)->getMaterial());
        self::assertSame(AgentState::REVOKED, $environment->agentRepository->all()[0]->getState());
        self::assertFalse($environment->agentRepository->replace($agent, $agent->revoke($this->now())));
    }

    public function test_direct_rotation_capable_write_uses_the_same_cancellation_without_building_rotation(): void
    {
        $environment = $this->issued();
        $original = $this->operation($environment);
        $agent = $environment->agentRepository->all()[0];
        // Hydrated successor exercises the public repository write contract, not the future rotation service.
        $successor = RehydratedAgentFixture::withCredential($agent, AgentCredentialId::generate(), 1);
        $environment->transaction->commitTransactional(function () use ($environment, $agent, $successor): void {
            self::assertTrue($environment->agentRepository->replace($agent, $successor));
        });
        self::assertSame($successor, $environment->agentRepository->all()[0]);
        self::assertNull($this->operation($environment)->getMaterial());
        self::assertSame(
            AgentCredentialDisposition::SUPERSEDED,
            $this->operation($environment)->getStatus()->getCredentialDisposition()
        );
        self::assertSame($original->getIssuance(), $this->operation($environment)->resolve($environment->request));
        self::assertFalse(new ControlledAgentDeliveryWriter($environment)->advance($original, 1, complete: true));
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        yield 'cancellation storage' => ['cancellation'];
        yield 'Agent storage after cancellation' => ['agent'];
        yield 'audit after both writes' => ['audit'];
        yield 'commit after all writes' => ['commit'];
    }

    #[DataProvider('faults')]
    public function test_failure_rolls_back_credential_copy_cancellation_and_audit(string $fault): void
    {
        $environment = $this->issued();
        $original = $this->operation($environment);
        $agent = $environment->agentRepository->all()[0];
        $versions = $environment->agentOperationRepository->versions;
        $auditEvidenceRepository = $environment->auditEvidenceRepository;
        $failure = new RuntimeException('Safe injected persistence failure.');
        if ($fault === 'cancellation') {
            $environment->agentOperationRepository->afterRetirement = static function (): void {
                throw new RuntimeException('unsafe-provider-detail original-test-secret');
            };
        } elseif ($fault === 'agent') {
            $environment->agentRepository->afterReplace = static function () use ($failure): void {
                throw $failure;
            };
        } elseif ($fault === 'audit') {
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
                $environment->transaction,
                failAfterSave: true
            );
        } else {
            $environment->transaction->failNextCommit = true;
        }

        try {
            $this->service($environment, $auditEvidenceRepository)->revoke('maintainer-42', $agent->getId());
            self::fail('Expected transaction failure.');
        } catch (RuntimeException $runtimeException) {
            self::assertStringNotContainsString('unsafe-provider-detail', (string) $runtimeException);
            self::assertStringNotContainsString('original-test-secret', (string) $runtimeException);
            if ($fault === 'agent') {
                self::assertSame($failure, $runtimeException);
            }
        }

        self::assertSame($agent, $environment->agentRepository->all()[0]);
        self::assertSame($original, $this->operation($environment));
        self::assertNotNull($this->operation($environment)->getMaterial());
        self::assertSame($versions, $environment->agentOperationRepository->versions);
        self::assertCount(1, $environment->auditEvidenceRepository->all());
        if ($fault === 'audit') {
            self::assertSame([], $auditEvidenceRepository->all());
        }

        self::assertCount(1, $environment->eventDispatcher->events());
        self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $environment->eventDispatcher->events()[0]);
        self::assertFalse($environment->agentOperationRepository->retirementLocked);
        self::assertFalse($environment->operationAuthorization->locked);
    }

    public function test_both_publication_failures_preserve_committed_revocation_and_rethrow_the_original_fault(): void
    {
        $environment = $this->issued();
        $failure = new RuntimeException('Safe publication failure.');
        $environment->eventDispatcher = new InMemoryEventDispatcher(
            static function (object $event) use ($failure): void {
                if ($event instanceof AgentCredentialRevoked) {
                    throw $failure;
                }

                throw new RuntimeException('Failure publisher is also unavailable.');
            }
        );
        try {
            $this->service($environment)->revoke('maintainer-42', $environment->agentRepository->all()[0]->getId());
            self::fail('Revocation does not use the issuance warning exception.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertSame(AgentState::REVOKED, $environment->agentRepository->all()[0]->getState());
        self::assertNull($this->operation($environment)->getMaterial());
        self::assertCount(2, $environment->auditEvidenceRepository->all());
        $this->expectException(AgentCredentialException::class);
        $this->service($environment)->revoke('maintainer-42', $environment->agentRepository->all()[0]->getId());
    }

    /** @return iterable<string, array{AgentDeliveryDisposition}> */
    public static function dispositions(): iterable
    {
        foreach (AgentDeliveryDisposition::cases() as $disposition) {
            yield $disposition->value => [$disposition];
        }
    }

    #[DataProvider('dispositions')]
    public function test_retirement_preserves_terminal_delivery_history_and_always_removes_material(
        AgentDeliveryDisposition $disposition
    ): void {
        $environment = $this->issued();
        $original = $this->operation($environment);
        $operation = new AgentCredentialOperation(
            $original->getCanonicalVersion(),
            $original->getCanonicalRequest(),
            $original->getIssuance(),
            $original->getMaterial(),
            $disposition,
            stateRevision: 7
        );
        $environment->agentOperationRepository->operations[$environment->key->toString()] = $operation;
        $this->service($environment)->revoke('maintainer-42', $environment->agentRepository->all()[0]->getId());
        $retired = $this->operation($environment);
        $expected = $disposition;
        if (in_array($disposition, [AgentDeliveryDisposition::PENDING, AgentDeliveryDisposition::RETRYABLE], true)) {
            $expected = AgentDeliveryDisposition::RETIRED;
        }

        self::assertSame($expected, $retired->getStatus()->getDeliveryDisposition());
        self::assertSame(AgentCredentialDisposition::REVOKED, $retired->getStatus()->getCredentialDisposition());
        self::assertSame(8, $retired->getStateRevision());
        self::assertNull($retired->getMaterial());
        self::assertFalse($retired->hasPendingDeliveryAtRevision(8));
    }

    public function test_capacity_and_material_cleanup_never_prevent_revocation_or_reuse_an_old_key(): void
    {
        $environment = new ProvisioningEnvironment();
        $limits = new AgentOperationLimits(128, 1, 1);
        $environment->bus(limits: $limits)->commandBus->execute(
            new ProvisionAgent($environment->key, $environment->request)
        );
        $newKey = new AgentOperationKey($environment->key->getScope(), AgentOperationId::generate());
        try {
            $environment->bus(limits: $limits)->commandBus->execute(new ProvisionAgent($newKey, $environment->request));
            self::fail('The one-slot override is full.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CAPACITY, $agentOperationRejectedException->getReason());
        }

        $original = $this->operation($environment);
        $environment->agentOperationRepository->operations[$environment->key->toString()] = $original->retireMaterial();
        $this->service($environment)->revoke('maintainer-42', $environment->agentRepository->all()[0]->getId());
        self::assertSame($original->getIssuance(), $environment->bus(
            limits: $limits
        )->dispatchAndRead(new ProvisionAgent(
            $environment->key,
            $environment->request
        ))->getIssuance());
        $next = $environment->bus(limits: $limits)->dispatchAndRead(
            new ProvisionAgent($newKey, $environment->request)
        )->getIssuance();
        self::assertNotNull($next);
        self::assertSame(2, $next->getDestinationWriteVersion());
        self::assertNotSame($original->getIssuance()->getDeliveryId(), $next->getDeliveryId());
        self::assertSame(
            AgentCredentialDisposition::REVOKED,
            $this->operation($environment)->getStatus()->getCredentialDisposition()
        );
        self::assertNull($this->operation($environment)->getMaterial());
    }

    public function test_missing_correlation_and_unsupported_transaction_participation_fail_closed(): void
    {
        foreach (['missing', 'ambiguous', 'no transaction', 'wrong connection', 'nested'] as $case) {
            $environment = $this->issued();
            $agent = $environment->agentRepository->all()[0];
            $original = $this->operation($environment);
            $agentRepository = $environment->agentRepository;
            if ($case === 'missing') {
                $environment->agentOperationRepository->operations = [];
            } elseif ($case === 'ambiguous') {
                $environment->agentOperationRepository->operations['duplicate'] = $original;
            } elseif ($case === 'wrong connection') {
                $agentRepository = new InMemoryAgentRepository(
                    new InMemoryUnitOfWork(),
                    agentOperationRepository: $environment->agentOperationRepository
                );
                $agentRepository->add($agent);
            }

            $before = $environment->agentOperationRepository->operations;
            try {
                if ($case === 'nested') {
                    $environment->transaction->commitTransactional(function () use ($environment, $agent): void {
                        $this->service($environment)->revoke('maintainer-42', $agent->getId());
                    });
                } elseif ($case === 'no transaction') {
                    $agentRepository->replace($agent, $agent->revoke($this->now()));
                } else {
                    $environment->transaction->commitTransactional(function () use ($agentRepository, $agent): void {
                        $agentRepository->replace($agent, $agent->revoke($this->now()));
                    });
                }

                self::fail('Unsupported composition must not perform partial cancellation.');
            } catch (RuntimeException) {
                self::assertSame($agent, $agentRepository->getById($agent->getId()));
                self::assertSame($before, $environment->agentOperationRepository->operations);
                self::assertCount(1, $environment->auditEvidenceRepository->all());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveFailures(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'ambiguous' => ['ambiguous'];
        yield 'cancellation' => ['cancellation'];
        yield 'no operations' => ['no operations'];
        yield 'wrong connection' => ['wrong connection'];
        yield 'no transaction' => ['no transaction'];
    }

    #[DataProvider('sensitiveFailures')]
    public function test_retirement_failure_debug_redacts_agents_with_exception_arguments_enabled(string $case): void
    {
        $environment = $this->issued();
        $agent = $environment->agentRepository->all()[0];
        $original = $this->operation($environment);
        $agentRepository = $environment->agentRepository;
        if ($case === 'missing') {
            $environment->agentOperationRepository->operations = [];
        } elseif ($case === 'ambiguous') {
            $environment->agentOperationRepository->operations['duplicate'] = $original;
        } elseif ($case === 'cancellation') {
            $environment->agentOperationRepository->afterRetirement = static function (): void {
                throw new RuntimeException('unsafe-provider-detail original-test-secret');
            };
        } elseif ($case === 'no operations') {
            $agentRepository = new InMemoryAgentRepository($environment->transaction);
            $agentRepository->add($agent);
        } elseif ($case === 'wrong connection') {
            $agentRepository = new InMemoryAgentRepository(
                new InMemoryUnitOfWork(),
                agentOperationRepository: $environment->agentOperationRepository
            );
            $agentRepository->add($agent);
        }

        $before = $environment->agentOperationRepository->operations;
        $versions = $environment->agentOperationRepository->versions;
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
            try {
                if ($case === 'no transaction') {
                    $agentRepository->replace($agent, $agent->revoke($this->now()));
                } elseif ($agentRepository !== $environment->agentRepository) {
                    $environment->transaction->commitTransactional(function () use ($agentRepository, $agent): void {
                        $agentRepository->replace($agent, $agent->revoke($this->now()));
                    });
                } else {
                    $this->service($environment)->revoke('maintainer-42', $agent->getId());
                }

                self::fail('Expected sanitized cancellation rejection.');
            } catch (AgentOperationRejectedException $failure) {
                $expectedReason = AgentOperationFailure::UNAVAILABLE;
                if (in_array($case, ['missing', 'ambiguous'], true)) {
                    $expectedReason = AgentOperationFailure::CONFLICT;
                }

                self::assertSame($expectedReason, $failure->getReason());
                self::assertNull($failure->getPrevious());
                $replacementFrames = 0;
                $trace = [];
                foreach ($failure->getTrace() as $frame) {
                    // Keep the whole failing call chain, but not PHPUnit's test-runner object graph.
                    if ($frame['function'] === __FUNCTION__) {
                        break;
                    }

                    $trace[] = $frame;
                    if (
                        ($frame['class'] ?? null) !== InMemoryAgentRepository::class
                        || $frame['function'] !== 'replace'
                    ) {
                        continue;
                    }

                    ++$replacementFrames;
                    self::assertCount(2, $frame['args']);
                    foreach ($frame['args'] as $argument) {
                        self::assertTrue(
                            $argument instanceof SensitiveParameterValue,
                            'Agent argument must be redacted.'
                        );
                    }
                }

                self::assertSame(1, $replacementFrames);
                $details = ['message' => $failure->getMessage(), 'trace' => $trace];
                ob_start();
                var_dump($details);
                $debug = ob_get_clean().print_r($details, true);
                $secrets = ['original-test-secret', 'auth-envelope:', 'ciphertext', 'unsafe-provider-detail'];
                foreach ($secrets as $secret) {
                    self::assertFalse(str_contains($debug, $secret), 'Failure debug must not expose sensitive data.');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        self::assertSame($agent, $agentRepository->getById($agent->getId()));
        self::assertSame($before, $environment->agentOperationRepository->operations);
        self::assertSame($versions, $environment->agentOperationRepository->versions);
        self::assertCount(1, $environment->auditEvidenceRepository->all());
        self::assertFalse($environment->transaction->transactionActive);
        self::assertFalse($environment->agentOperationRepository->retirementLocked);
        self::assertFalse($environment->operationAuthorization->locked);
    }

    public function test_consumer_authority_writers_share_retirement_fences_and_aba_invalidates_old_snapshots(): void
    {
        foreach (['caller', 'destination'] as $case) {
            $environment = $this->issued();
            $writer = new ControlledAgentDeliveryWriter($environment);
            $epoch = $environment->operationAuthorization->epoch;
            self::assertTrue($writer->advance($this->operation($environment), $epoch));
            $admitted = $this->operation($environment);
            $scope = $environment->key->getScope()->toString();
            $slot = $environment->request->getDestination()->getId()->toString();
            $environment->operationAuthorization->changeAuthority(
                function () use ($environment, $case, $scope, $slot): void {
                    if ($case === 'caller') {
                        $environment->operationAuthorization->scopes[$scope] = false;
                    } else {
                        $environment->operationAuthorization->destinations[$slot] = 2;
                    }
                }
            );
            self::assertFalse($writer->advance($admitted, $epoch, complete: true));
            $environment->operationAuthorization->changeAuthority(function () use ($environment, $scope, $slot): void {
                $environment->operationAuthorization->scopes[$scope] = true;
                $environment->operationAuthorization->destinations[$slot] = 1;
            });
            self::assertFalse($writer->advance($admitted, $epoch, complete: true));
            self::assertTrue($writer->advance($admitted, $environment->operationAuthorization->epoch));
            $before = $environment->operationAuthorization->epoch;
            $environment->agentOperationRepository->afterRetirement = function () use ($environment, $before): void {
                $environment->operationAuthorization->changeAuthority(function () use ($environment): void {
                    $environment->operationAuthorization->delegationExpired = true;
                });
                self::assertSame($before, $environment->operationAuthorization->epoch);
                self::assertFalse($environment->operationAuthorization->delegationExpired);
            };
            $this->service($environment)->revoke('maintainer-42', $environment->agentRepository->all()[0]->getId());
            self::assertSame($before + 1, $environment->operationAuthorization->epoch);
            self::assertTrue($environment->operationAuthorization->delegationExpired);
            self::assertNull($this->operation($environment)->getMaterial());
        }
    }

    public function test_invalid_or_stale_direct_successors_cannot_retire_or_revive_credentials(): void
    {
        $environment = $this->issued();
        $agent = $environment->agentRepository->all()[0];
        $original = $this->operation($environment);
        $invalid = [
            $agent,
            $agent->grantPermission(PermissionId::generate(), $this->now()),
            RehydratedAgentFixture::withCredential($agent, AgentCredentialId::generate(), 0),
            RehydratedAgentFixture::withCredential($agent, $agent->getCredentialId(), 1),
            RehydratedAgentFixture::withCredential(
                $agent,
                $agent->getCredentialId(),
                0,
                AgentState::REVOKED,
                envelope: 'other'
            ),
            RehydratedAgentFixture::withCredential($agent, AgentCredentialId::generate(), 0, AgentState::REVOKED),
            RehydratedAgentFixture::withCredential($agent, $agent->getCredentialId(), 1, AgentState::REVOKED),
            Agent::provision(
                AgentId::generate(),
                $agent->getName(),
                AgentCredentialId::generate(),
                'other',
                $this->now()
            ),
            Agent::provision(
                $agent->getId(),
                AgentName::fromString('Other'),
                AgentCredentialId::generate(),
                'other',
                $this->now()
            )
        ];
        foreach ($invalid as $replacement) {
            self::assertFalse($environment->agentRepository->replace($agent, $replacement));
            self::assertSame($original, $this->operation($environment));
        }

        $this->service($environment)->revoke('maintainer-42', $agent->getId());
        $revoked = $environment->agentRepository->all()[0];
        self::assertFalse($environment->agentRepository->replace($revoked, $agent));
        self::assertFalse($environment->agentRepository->replace($agent, $agent->revoke($this->now())));
    }

    private function issued(): ProvisioningEnvironment
    {
        $environment = new ProvisioningEnvironment();
        $environment->bus()->commandBus->execute(new ProvisionAgent($environment->key, $environment->request));
        $environment->eventDispatcher = new InMemoryEventDispatcher();

        return $environment;
    }

    private function operation(ProvisioningEnvironment $environment): AgentCredentialOperation
    {
        return $environment->agentOperationRepository->operations[$environment->key->toString()];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-28T12:00:00+00:00');
    }

    private function service(
        ProvisioningEnvironment $environment,
        ?InMemoryAuditEvidenceRepository $auditEvidenceRepository = null
    ): AgentCredentialLifecycleService {
        return new AgentCredentialLifecycleService(
            $environment->agentRepository,
            $auditEvidenceRepository ?? $environment->auditEvidenceRepository,
            new FixedClock($this->now()),
            $environment->transaction,
            $environment->eventDispatcher
        );
    }
}
