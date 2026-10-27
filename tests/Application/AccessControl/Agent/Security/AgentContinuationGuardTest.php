<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentContinuationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ControlledContinuationAuthority;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\GuardedContinuationProbe;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use TypeError;

#[CoversClass(AgentContinuationBinding::class)]
#[CoversClass(AgentContinuationContext::class)]
#[CoversClass(AgentContinuationGuard::class)]
#[CoversClass(AgentContinuationRejectedException::class)]
#[CoversClass(CurrentAgentPrincipalProvider::class)]
#[CoversClass(Agent::class)]
final class AgentContinuationGuardTest extends TestCase
{
    public function test_that_only_admitted_request_provenance_can_create_a_request_binding(): void
    {
        [$provider, $request, $nonce, $verifier] = $this->requestProvider();
        $this->rejects(fn() => $provider->continuationContext());
        $binding = AgentContinuationBinding::fromRequest($provider, $request, 'continuation');
        $again = AgentContinuationBinding::fromRequest($provider, $request, 'continuation');

        self::assertTrue(new ReflectionClass(AgentContinuationBinding::class)->getConstructor()->isPrivate());
        self::assertTrue($binding->getContext()->getAgentId()->equals($this->agent()->getId()));
        self::assertTrue($binding->getContext()->getCredentialId()->equals($this->agent()->getCredentialId()));
        self::assertSame(0, $binding->getContext()->getCredentialRevision());
        self::assertEquals($this->now(), $binding->getContext()->getAuthenticatedAt());
        self::assertEquals($binding->getContext(), $again->getContext());
        self::assertSame(1, $nonce->consumptionCalls());
        self::assertSame(1, $verifier->calls());
        self::assertSame([], $binding->__debugInfo());
        self::assertSame([], $binding->getContext()->__debugInfo());
        self::assertSame('{}', json_encode($binding, JSON_THROW_ON_ERROR));
        $this->rejects(fn(): string => serialize($binding));
        $class = AgentContinuationBinding::class;
        $this->rejects(fn(): mixed => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'));
    }

    public function test_that_rejected_authentication_and_incomplete_principals_cannot_issue_bindings(): void
    {
        [$provider, $request, $nonce] = $this->requestProvider(validSignature: false);
        $this->rejects(
            fn(): AgentContinuationBinding => AgentContinuationBinding::fromRequest($provider, $request, 'rejected')
        );
        $this->rejects(fn() => $provider->continuationContext());
        self::assertSame(0, $nonce->consumptionCalls());

        [$provider, $request, $nonce] = $this->requestProvider(completePermissions: false);
        $this->rejects(
            fn(): AgentContinuationBinding => AgentContinuationBinding::fromRequest($provider, $request, 'incomplete')
        );
        $this->rejects(fn() => $provider->continuationContext());
        self::assertSame(1, $nonce->consumptionCalls());
    }

    public function test_that_a_copied_principal_or_context_is_not_an_admitted_binding(): void
    {
        [$provider, $request] = $this->requestProvider();
        $principal = $provider->resolve($request, 'original');
        $copiedPrincipal = new AuthenticatedAgentPrincipal(
            $principal->getAgentId(),
            $principal->getCredentialId(),
            $principal->getCredentialRevision(),
            $principal->getPermissionAssignmentRevision(),
            $principal->getPermissions()
        );
        [$guard, $state] = $this->guard();
        foreach ([$copiedPrincipal, $provider->continuationContext()] as $forged) {
            try {
                // @phpstan-ignore argument.type (Copied public authority is deliberately not a binding.)
                $guard->run($forged, $this->requirements(), $state, static function (): void {
                    self::fail('Copied public fields cannot admit work.');
                });
                self::fail('Only the binding type may cross admission.');
            } catch (TypeError) {
                self::assertSame(0, $state->acquisitions);
            }
        }
    }

    public function test_that_worker_handoff_requires_a_trusted_verifier_and_preserves_original_age(): void
    {
        [$provider, $request, $nonce, $verifier] = $this->requestProvider();
        $original = AgentContinuationBinding::fromRequest($provider, $request, 'original');
        $record = $original->getContext();
        $handoff = new class ($record) implements AgentContinuationHandoff {
            public bool $verified = true;

            public int $calls = 0;

            public function __construct(private readonly AgentContinuationContext $record)
            {
            }

            public function verify(): AgentContinuationContext
            {
                ++$this->calls;
                if (!$this->verified) {
                    throw new RuntimeException('Private handoff integrity or recipient failure.');
                }

                return new AgentContinuationContext(
                    $this->record->getAgentId(),
                    $this->record->getCredentialId(),
                    $this->record->getCredentialRevision(),
                    $this->record->getAuthenticatedAt()
                );
            }
        };
        $workerBinding = AgentContinuationBinding::fromHandoff($handoff);
        self::assertNotSame($record, $workerBinding->getContext());
        self::assertEquals($record, $workerBinding->getContext());
        [$guard, $state] = $this->guard(new FixedClock($this->now()->modify('+299 seconds')));
        $outputs = [];
        $guard->run($workerBinding, $this->requirements(), $state, function () use (&$outputs): void {
            $outputs[] = 'worker output';
        });
        $handoff->verified = false;
        $this->rejects(fn(): AgentContinuationBinding => AgentContinuationBinding::fromHandoff($handoff));
        self::assertSame(2, $handoff->calls);
        [$expiredGuard] = $this->guard(new FixedClock($this->now()->modify('+300 seconds')), $state);
        $this->rejects(fn() => $expiredGuard->run(
            $workerBinding,
            $this->requirements(),
            $state,
            static function (): void {
                self::fail('A handoff cannot refresh original authentication age.');
            }
        ));
        self::assertSame(['worker output'], $outputs);
        self::assertSame(1, $nonce->consumptionCalls());
        self::assertSame(1, $verifier->calls());
    }

    public function test_that_validity_is_checked_after_scope_and_fence_wait_not_before_them(): void
    {
        $binding = $this->binding();
        foreach (['-1 second', '+300 seconds', '+301 seconds'] as $offset) {
            [$guard, $state] = $this->guard(new FixedClock($this->now()->modify($offset)));
            $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
                self::fail('Future or expired provenance must not admit effects.');
            }));
            self::assertSame(1, $state->scopeChecks);
            self::assertFalse($state->held);
        }

        $clock = new FixedClock($this->now(), $this->now()->modify('+300 seconds'));
        [$guard, $state] = $this->guard($clock);
        $state->afterScopeRead = static function () use ($clock): void {
            $clock->now();
        };
        $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
            self::fail('Expiry during scope resolution denies admission.');
        }));
        self::assertSame(2, $clock->calls());
        self::assertFalse(new AgentContinuationContext(
            $this->agent()->getId(),
            $this->agent()->getCredentialId(),
            -1,
            $this->now()
        )->isValidAt($this->now()));
    }

    public function test_that_queued_items_steps_and_retained_inputs_need_new_admission(): void
    {
        foreach (['queued disclosure', 'background step', 'retained input consumption'] as $scenario) {
            foreach (['revoked', 'rotated', 'permission removed', 'scope changed', 'fault'] as $change) {
                [$guard, $state] = $this->guard();
                [$provider, $request, $nonce, $verifier] = $this->requestProvider();
                $binding = AgentContinuationBinding::fromRequest($provider, $request, 'runtime');
                $runtime = new GuardedContinuationProbe();
                $protectedAction = match ($scenario) {
                    'queued disclosure' => $runtime->disclose(...),
                    'background step'   => $runtime->step(...),
                    default             => $runtime->consume(...)
                };
                $perform = static function () use ($state, $protectedAction): void {
                    self::assertTrue($state->held);
                    $protectedAction();
                };
                $guard->run($binding, $this->requirements(), $state, $perform);
                $afterFirst = clone $runtime;
                $state->write(function () use ($state, $change): void {
                    $state->agent = match ($change) {
                        'revoked'            => $this->agent()->revoke($this->now()),
                        'rotated'            => $this->rotatedAgent(),
                        'permission removed' => $this->agent()->revokePermission(
                            $this->permission()->getId(),
                            $this->now()
                        ),
                        default              => $state->agent
                    };
                    if ($change === 'scope changed') {
                        $state->scopeAllowed = false;
                    }

                    if ($change === 'fault') {
                        $state->permissionFailure = new RuntimeException('Private storage failure.');
                    }
                });
                $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, $perform));
                self::assertEquals($afterFirst, $runtime, $scenario.' / '.$change);
                if ($scenario === 'queued disclosure') {
                    self::assertSame(['prepared-first'], $runtime->emitted);
                    self::assertSame(['prepared-second'], $runtime->queued);
                } elseif ($scenario === 'background step') {
                    self::assertSame(1, $runtime->steps);
                    self::assertSame(['step-1'], $runtime->effects);
                } else {
                    self::assertSame(['accepted-first'], $runtime->consumed);
                    self::assertSame(['accepted-second'], $runtime->retained);
                }

                self::assertSame(1, $nonce->consumptionCalls());
                self::assertSame(1, $verifier->calls());
                self::assertSame($state->acquisitions, $state->releases);
            }
        }
    }

    public function test_that_grants_removals_regrants_and_definition_changes_are_current_not_snapshot_authority(): void
    {
        $binding = $this->binding();
        [$guard, $state] = $this->guard();
        $outputs = [];
        $emit = static function () use (&$outputs): void {
            $outputs[] = 'protected';
        };
        $run = fn() => $guard->run($binding, $this->requirements(), $state, $emit);
        $run();
        $state->agent = $this->agent()->revokePermission($this->permission()->getId(), $this->now());
        $state->definitions = [];
        $this->rejects($run);
        $state->agent = $state->agent->grantPermission($this->permission()->getId(), $this->now());
        $state->definitions = [$this->permission()];
        $run();
        $state->definitions = [$this->permission()->reconcileManaged(
            PermissionName::fromString('OTHER_PERMISSION'),
            PermissionTier::ADMIN_SAFE,
            $this->now()
        )];
        $this->rejects($run);
        $guard->run($binding, [PermissionName::fromString('OTHER_PERMISSION')], $state, $emit);
        self::assertSame(['protected', 'protected', 'protected'], $outputs);
        self::assertSame(4, $state->agent->getPermissionAssignmentRevision());
    }

    public function test_that_protected_tier_definitions_cannot_supply_direct_agent_authority(): void
    {
        [$guard, $state] = $this->guard();
        $state->definitions = [$this->permission()->reconcileManaged(
            PermissionName::fromString('VIEW_AGENTS'),
            PermissionTier::SUPER_ADMIN_ONLY,
            $this->now()
        )];
        $this->rejects(fn() => $guard->run($this->binding(), $this->requirements(), $state, static function (): void {
            self::fail('Protected human authority must never be inferred from a broken Agent assignment.');
        }));
    }

    public function test_that_input_acceptance_and_prior_consumption_never_supply_authority(): void
    {
        [$guard, $state] = $this->guard();
        $binding = $this->binding();
        $retained = ['accepted-first', 'accepted-second'];
        $consumed = [];
        $consume = static function () use (&$retained, &$consumed): void {
            $consumed[] = array_shift($retained);
        };
        $guard->run($binding, $this->requirements(), $state, $consume);
        $state->scopeAllowed = false;
        $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, $consume));
        self::assertSame(['accepted-second'], $retained);
        self::assertSame(['accepted-first'], $consumed);
        $state->scopeAllowed = true;
        $guard->run($binding, $this->requirements(), $state, $consume);
        self::assertSame([], $retained);
        self::assertSame(['accepted-first', 'accepted-second'], $consumed);
    }

    public function test_that_missing_mismatched_and_duplicate_permission_definitions_fail_closed(): void
    {
        foreach ([[], [$this->otherPermission()], [$this->permission(), $this->permission()]] as $definitions) {
            [$guard, $state] = $this->guard();
            $state->definitions = $definitions;
            $binding = $this->binding();
            $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
                self::fail('Invalid exact resolution must prevent effects.');
            }));
            self::assertSame(0, $state->scopeChecks);
        }
    }

    public function test_that_all_requirements_must_hold_and_scope_sees_fresh_complete_authority(): void
    {
        [$guard, $state] = $this->guard();
        $binding = $this->binding();
        $view = PermissionName::fromString('VIEW_AGENTS');
        $other = PermissionName::fromString('OTHER_PERMISSION');
        foreach ([[], [$view, $other], [$other, $view], ['VIEW_AGENTS'], ['named' => $view]] as $requirements) {
            $this->rejects(fn() => $guard->run($binding, $requirements, $state, static function (): void {
                self::fail('No partial requirement list can admit work.');
            }));
        }

        $state->agent = $this->agent()->grantPermission($this->otherPermission()->getId(), $this->now());
        $state->definitions = [$this->otherPermission(), $this->permission()];

        $scope = $this->createMock(AgentContinuationScope::class);
        $scope->expects(self::once())->method('isAllowed')->willReturnCallback(
            static function (AuthenticatedAgentPrincipal $principal) use ($view, $other, $state): bool {
                self::assertTrue($state->held);
                self::assertSame(3, $principal->getPermissionAssignmentRevision());
                self::assertTrue($principal->hasPermission($view));
                self::assertTrue($principal->hasPermission($other));
                self::assertFalse($principal->hasRole(RoleName::fromString('ROLE_SUPER_ADMIN')));

                return true;
            }
        );
        // The fixture's fence binds the exact scope instance; use a composite facade for this inspected scope.
        $fence = $this->createStub(AgentContinuationFence::class);
        $fence->method('synchronized')->willReturnCallback(
            static function (AgentId $id, AgentContinuationScope $provided, Closure $admit) use ($state, $scope): void {
                self::assertSame($scope, $provided);
                $state->synchronized($id, $state, $admit);
            }
        );
        [$guard] = $this->guard(state: $state, fence: $fence);
        $calls = 0;
        $guard->run($binding, [$view, $other, $view], $scope, static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(1, $calls);
    }

    public function test_that_agent_absence_wrong_identity_and_original_credential_mismatches_deny(): void
    {
        $agent = $this->agent();
        $wrongAgent = Agent::provision(
            AgentId::generate(),
            $agent->getName(),
            $agent->getCredentialId(),
            'encrypted:fixture',
            $this->now()
        );
        $wrongRevision = Agent::reconstitute(
            $agent->getId(),
            $agent->getName(),
            AgentState::ACTIVE,
            $agent->getCredentialId(),
            1,
            'encrypted:fixture',
            $agent->getPermissionIds(),
            $agent->getPermissionAssignmentRevision(),
            $agent->getCreatedAt(),
            $agent->getUpdatedAt()
        );
        foreach ([null, $wrongAgent, $this->rotatedAgent(), $wrongRevision] as $current) {
            [$guard, $state] = $this->guard();
            $state->agent = $current;
            $binding = $this->binding();
            $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
                self::fail('Only the exact original active credential may continue.');
            }));
        }
    }

    public function test_that_faults_before_admission_are_neutral_without_private_causes_or_effects(): void
    {
        foreach (['agentFailure', 'permissionFailure', 'scopeFailure', 'unsupported', 'unreconciled'] as $fault) {
            [$guard, $state] = $this->guard();
            if ($fault === 'unsupported') {
                $state->supported = false;
            } elseif ($fault === 'unreconciled') {
                $state->contract = new AgentOperationContract(
                    1,
                    2,
                    1,
                    2,
                    AgentOperationContract::REQUIRED_CAPABILITIES,
                    null
                );
            } else {
                $state->{$fault} = new RuntimeException('PRIVATE_SCOPE_PERMISSION_CREDENTIAL_CAUSE');
            }

            $binding = $this->binding();
            $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
                self::fail('Verification failure must not execute effects.');
            }));
            self::assertFalse($state->held);
            self::assertSame($state->acquisitions, $state->releases);
        }
    }

    public function test_that_changes_which_win_before_fence_acquisition_deny(): void
    {
        foreach (['credential', 'permission', 'scope'] as $writer) {
            [$guard, $state] = $this->guard();
            $state->beforeAcquire = function () use ($state, $writer): void {
                $state->write(function () use ($state, $writer): void {
                    if ($writer === 'credential') {
                        $state->agent = $this->rotatedAgent();
                    } elseif ($writer === 'permission') {
                        $state->agent = $this->agent()->revokePermission($this->permission()->getId(), $this->now());
                        $state->definitions = [];
                    } else {
                        $state->scopeAllowed = false;
                    }
                });
            };
            $binding = $this->binding();
            $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
                self::fail('A writer that wins first changes the admitted authority.');
            }));
        }
    }

    public function test_that_all_authority_writers_wait_through_checks_and_action_then_win_after_release(): void
    {
        foreach (['revocation', 'rotation', 'assignment', 'definition', 'scope'] as $writerType) {
            foreach (['afterAgentRead', 'afterPermissionRead', 'afterScopeRead', 'action'] as $point) {
                [$guard, $state] = $this->guard();
                $writerBlocked = 0;
                $writer = function () use ($state, $writerType): void {
                    if ($writerType === 'scope') {
                        $state->scopeAllowed = false;
                    } elseif ($writerType === 'definition') {
                        $state->definitions = [];
                    } else {
                        $state->agent = match ($writerType) {
                            'revocation' => $this->agent()->revoke($this->now()),
                            'rotation'   => $this->rotatedAgent(),
                            default      => $this->agent()->revokePermission(
                                $this->permission()->getId(),
                                $this->now()
                            )
                        };
                    }
                };
                $attempt = static function () use ($state, $writer, &$writerBlocked): void {
                    try {
                        $state->write($writer);
                        self::fail('Every authority writer must share the held fence.');
                    } catch (RuntimeException) {
                        ++$writerBlocked;
                    }
                };
                if ($point !== 'action') {
                    $state->{$point} = $attempt;
                }

                $effects = 0;
                $binding = $this->binding();
                $guard->run($binding, $this->requirements(), $state, static function () use (
                    $state,
                    $point,
                    $attempt,
                    &$effects
                ): void {
                    self::assertTrue($state->held);
                    if ($point === 'action') {
                        $attempt();
                    }

                    ++$effects;
                });
                self::assertSame(1, $writerBlocked);
                self::assertSame(1, $effects);
                self::assertFalse($state->held);
                if ($point !== 'action') {
                    $state->{$point} = null;
                }

                $state->write($writer);
                $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
                    self::fail('The next action must see the writer that won after release.');
                }));
                self::assertSame(1, $effects, 'No retraction of the already admitted action is claimed.');
            }
        }
    }

    public function test_that_pre_admission_scope_fault_releases_the_fence_and_never_caches_allow(): void
    {
        [$guard, $state] = $this->guard();
        $binding = $this->binding();
        $calls = 0;
        $action = static function () use (&$calls): void {
            ++$calls;
        };
        $guard->run($binding, $this->requirements(), $state, $action);
        $state->scopeFailure = new RuntimeException('Private relationship authority outage.');
        $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, $action));
        self::assertSame(1, $calls);
        self::assertFalse($state->held);
        $state->scopeFailure = null;
        $guard->run($binding, $this->requirements(), $state, $action);
        self::assertSame(2, $calls);
    }

    public function test_that_action_faults_propagate_without_a_false_denial_or_rollback_claim(): void
    {
        [$guard, $state] = $this->guard();
        $failure = new RuntimeException('Consumer-owned effect failure.');
        $effects = [];
        try {
            $guard->run($this->binding(), $this->requirements(), $state, static function () use (
                &$effects,
                $failure
            ): void {
                $effects[] = 'already emitted';
                throw $failure;
            });
            self::fail('The effect fault must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
            self::assertSame(['already emitted'], $effects);
            self::assertFalse($state->held);
        }
    }

    public function test_that_missing_deferred_and_retried_fence_callbacks_cannot_create_a_reusable_allow(): void
    {
        $binding = $this->binding();
        foreach (['missing', 'deferred', 'twice'] as $mode) {
            $saved = null;
            $calls = 0;
            [, $state] = $this->guard();
            $fence = $this->createStub(AgentContinuationFence::class);
            $fence->method('synchronized')->willReturnCallback(
                static function (
                    AgentId $id,
                    AgentContinuationScope $scope,
                    Closure $admit
                ) use (
                    $mode,
                    &$saved,
                    $state
                ): void {
                    $saved = $admit;
                    if ($mode === 'twice') {
                        $state->synchronized($id, $state, static function () use ($admit): void {
                            $admit();
                            $admit();
                        });
                    }
                }
            );
            [$guard] = $this->guard(state: $state, fence: $fence);
            $action = static function () use (&$calls): void {
                ++$calls;
            };
            $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, $action));
            self::assertSame($mode === 'twice' ? 1 : 0, $calls);
            self::assertInstanceOf(Closure::class, $saved);
            $this->rejects($saved);
            self::assertSame($mode === 'twice' ? 1 : 0, $calls);
        }
    }

    public function test_that_ordinary_short_request_snapshot_and_later_nonce_rules_are_unchanged(): void
    {
        [$provider, $request, $nonce, $verifier, $agents, $permissions, $uow] = $this->requestProvider();
        $principal = $provider->resolve($request, 'first');
        $binding = AgentContinuationBinding::fromRequest($provider, $request, 'same');
        $original = $agents->getById($this->agent()->getId());
        self::assertTrue($agents->replacePermissionAssignments(
            $original,
            $original->revokePermission($this->permission()->getId(), $this->now())
        ));
        self::assertSame($principal, $provider->resolve($request, 'cached'));
        self::assertTrue($principal->hasPermission($this->requirements()[0]));
        [$guard, $state] = $this->guard();
        $state->agent = $agents->getById($original->getId());
        $state->definitions = [];
        $this->rejects(fn() => $guard->run($binding, $this->requirements(), $state, static function (): void {
            self::fail('A request snapshot is not continuing authority.');
        }));
        $later = new CurrentAgentPrincipalProvider(
            $agents,
            $permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock($this->now()),
            $nonce,
            $uow
        );
        try {
            $later->resolve($request, 'replay');
            self::fail('A fresh provider must not replay the original nonce.');
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertSame(2, $nonce->consumptionCalls());
        }

        $freshRequest = $this->request(nonce: 'fresh-fixture-nonce');
        $freshVerifier = new FixedHmacSignedAgentRequestVerifier($freshRequest, 'fixture');
        $fresh = new CurrentAgentPrincipalProvider(
            $agents,
            $permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $freshVerifier,
            new FixedClock($this->now()),
            $nonce,
            $uow
        );
        self::assertFalse($fresh->resolve($freshRequest, 'fresh')->hasPermission($this->requirements()[0]));
        self::assertSame(3, $nonce->consumptionCalls());
        self::assertSame(1, $freshVerifier->calls());
        self::assertSame($principal, $provider->resolve($request, 'still-cached'));
    }

    /** @phpstan-param Closure(): mixed $operation */
    private function rejects(Closure $operation): void
    {
        try {
            $operation();
            self::fail('Protected continuation must be unavailable.');
        } catch (AgentContinuationRejectedException $agentContinuationRejectedException) {
            self::assertSame('Agent continuation unavailable.', $agentContinuationRejectedException->getMessage());
            self::assertNull($agentContinuationRejectedException->getPrevious());
            self::assertSame(0, $agentContinuationRejectedException->getCode());
        }
    }

    private function binding(): AgentContinuationBinding
    {
        [$provider, $request] = $this->requestProvider();

        return AgentContinuationBinding::fromRequest($provider, $request, 'binding');
    }

    /**
     * @return array{AgentContinuationGuard, ControlledContinuationAuthority}
     */
    private function guard(
        ?FixedClock $clock = null,
        ?ControlledContinuationAuthority $state = null,
        ?AgentContinuationFence $fence = null
    ): array {
        $state ??= new ControlledContinuationAuthority($this->agent(), [$this->permission()]);
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('getOperationContract')->willReturnCallback($state->readContract(...));
        $agents->method('getById')->willReturnCallback(static fn(): ?Agent => $state->readAgent());
        $permissions = new InMemoryPermissionRepository(getByIdsResult: $state->readPermissions(...));

        return [
            new AgentContinuationGuard($agents, $permissions, $clock ?? new FixedClock($this->now()), $fence ?? $state),
            $state
        ];
    }

    /**
     * @return array{
     *     CurrentAgentPrincipalProvider, SignedAgentRequest, InMemoryAgentRequestNonceConsumer,
     *     FixedHmacSignedAgentRequestVerifier, InMemoryAgentRepository, PermissionRepository, InMemoryUnitOfWork
     * }
     */
    private function requestProvider(bool $validSignature = true, bool $completePermissions = true): array
    {
        $agents = new InMemoryAgentRepository();
        $agents->add($this->agent());

        $permissions = new InMemoryPermissionRepository();
        if ($completePermissions) {
            $permissions->add($this->permission());
        }

        $uow = new InMemoryUnitOfWork();
        $nonce = new InMemoryAgentRequestNonceConsumer($agents, $uow);
        $request = $this->request();
        $verifier = new FixedHmacSignedAgentRequestVerifier($request, 'fixture');
        $provider = new CurrentAgentPrincipalProvider(
            $agents,
            $permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock($this->now()),
            $nonce,
            $uow
        );

        return [
            $provider,
            $this->request($validSignature ? 'fixture-signature' : 'invalid'),
            $nonce,
            $verifier,
            $agents,
            $permissions,
            $uow
        ];
    }

    private function request(
        string $signature = 'fixture-signature',
        string $nonce = 'fixture-nonce'
    ): SignedAgentRequest {
        return new SignedAgentRequest(
            'POST',
            'continuation.example',
            '/protected',
            '',
            $this->now(),
            $nonce,
            $this->agent()->getCredentialId(),
            'HMAC-SHA256',
            $signature,
            null,
            ''
        );
    }

    private function agent(): Agent
    {
        return Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000201'),
            AgentName::fromString('Continuation fixture'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000202'),
            'encrypted:fixture',
            $this->now()
        )->grantPermission($this->permission()->getId(), $this->now());
    }

    private function rotatedAgent(): Agent
    {
        return $this->agent()->rotateRecoverableCredential(
            $this->agent()->getCredentialId(),
            0,
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000203'),
            'encrypted:successor',
            $this->now()
        );
    }

    private function permission(): Permission
    {
        return Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000204'),
            PermissionName::fromString('VIEW_AGENTS'),
            PermissionTier::ADMIN_SAFE,
            $this->now()
        );
    }

    private function otherPermission(): Permission
    {
        return Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000205'),
            PermissionName::fromString('OTHER_PERMISSION'),
            $this->now()
        );
    }

    /** @return non-empty-list<PermissionName> */
    private function requirements(): array
    {
        return [PermissionName::fromString('VIEW_AGENTS')];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-27T12:00:00+00:00');
    }
}
