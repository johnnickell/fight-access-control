<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(CreateFeatureHandler::class)]
#[CoversClass(RemoveFeatureHandler::class)]
final class FeatureLifecycleAuditTest extends TestCase
{
    public static function operations(): iterable
    {
        yield 'create' => ['create'];
        yield 'remove' => ['remove'];
    }

    #[DataProvider('operations')]
    public function test_enabled_mapping_records_actual_state_and_round_tripped_correlation(string $operation): void
    {
        $fixture = new FeatureLifecycleAuditFixture($operation);
        $message = CommandMessage::arrayDeserialize(CommandMessage::create($fixture->command)->arraySerialize());
        $fixture->bus->dispatch($message);
        self::assertCount(1, $fixture->audit->all());
        $evidence = $fixture->audit->all()[0];
        $current = $fixture->features->getByName($fixture->original->getName());
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        self::assertEquals($fixture->command->getActorId(), $evidence->actorId());
        self::assertEquals($message->id(), $evidence->getCorrelation()?->getMessageId());
        self::assertEquals($fixture->time, $evidence->getOccurredAt());
        self::assertSame(
            $operation === 'create' ? AuditAction::FEATURE_CREATED : AuditAction::FEATURE_REMOVED,
            $evidence->action()
        );
        self::assertSame([
            'before' => $operation === 'create' ? null : [
                'revision'      => 7,
                'status'        => 'preview',
                'permission_id' => $fixture->permission->getId()->toString()
            ],
            'after'  => $operation === 'remove' ? null : [
                'revision'      => 1,
                'status'        => 'off',
                'permission_id' => $fixture->permission->getId()->toString()
            ]
        ], $evidence->getFacts()->toArray());
        self::assertEquals($current?->getId() ?? $fixture->original->getId(), $evidence->getSubject()->getId());
        self::assertStringNotContainsString('dashboard', json_encode($evidence->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame($fixture->unit, $fixture->audit->transactionIdentity());
        self::assertTrue($fixture->unit->transactionCompleted);
        $this->assertPermissionFence($fixture, $operation === 'create');
    }

    #[DataProvider('operations')]
    public function test_disabled_omitted_and_null_need_neither_actor_nor_time(string $operation): void
    {
        foreach ([false, true] as $omit) {
            $clock = $this->createMock(Clock::class);
            $clock->expects(self::never())->method('now');
            $fixture = new FeatureLifecycleAuditFixture(
                $operation,
                enabled: false,
                omit: $omit,
                actor: false,
                clock: $clock
            );
            $fixture->bus->execute($fixture->command);
            self::assertSame([], $fixture->audit->all());
            self::assertTrue($fixture->unit->transactionCompleted);
            $this->assertPermissionFence($fixture, $operation === 'create');
        }
    }

    #[DataProvider('operations')]
    public function test_missing_actor_rejects_before_the_first_mutation(string $operation): void
    {
        $fixture = new FeatureLifecycleAuditFixture($operation, actor: false);
        self::assertInstanceOf(InvalidInput::class, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
        self::assertSame(0, $fixture->features->writes);
        $this->assertOutcome($fixture, $operation, false);
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_state_references_and_evidence(string $operation): void
    {
        foreach ([false, true] as $before) {
            $fixture = new FeatureLifecycleAuditFixture(
                $operation,
                failAudit: !$before,
                failBeforeAudit: $before,
                events: new InMemoryEventDispatcher(static function (): void {
                    throw new RuntimeException('Secondary notification failure.');
                })
            );
            self::assertSame($fixture->audit->failure(), null);
            $failure = $this->failure(fn() => $fixture->bus->execute($fixture->command));
            self::assertSame($fixture->audit->failure(), $failure);
            $this->assertOutcome($fixture, $operation, false);
        }
    }

    #[DataProvider('operations')]
    public function test_uncertain_commit_preserves_both_possible_persisted_outcomes(string $operation): void
    {
        foreach ([false, true] as $committed) {
            $fixture = new FeatureLifecycleAuditFixture($operation);
            $primary = new RuntimeException('Commit acknowledgement unavailable.');
            $fault = static function () use ($primary): void {
                throw $primary;
            };
            if ($committed) {
                $fixture->unit->afterCommit = $fault;
            } else {
                $fixture->unit->beforeCommit = $fault;
            }

            self::assertSame($primary, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
            $this->assertOutcome($fixture, $operation, $committed);
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->events->events()[0]);
        }
    }

    #[DataProvider('operations')]
    public function test_final_commit_failure_removes_already_mapped_evidence(string $operation): void
    {
        $fixture = new FeatureLifecycleAuditFixture($operation);
        $fixture->unit->beforeCommit = static function () use ($fixture): void {
            self::assertCount(1, $fixture->audit->all());
            self::assertTrue($fixture->unit->transactionActive);
        };
        $fixture->unit->failNextCommit = true;
        $this->failure(fn() => $fixture->bus->execute($fixture->command));
        $this->assertOutcome($fixture, $operation, false);
    }

    #[DataProvider('operations')]
    public function test_both_publications_fail_without_undoing_committed_evidence(string $operation): void
    {
        $primary = new RuntimeException('Success subscriber failure.');
        $attempts = [];
        $fixture = new FeatureLifecycleAuditFixture($operation, events: new InMemoryEventDispatcher(
            static function (Event $event) use ($primary, &$attempts, &$fixture): void {
                self::assertTrue($fixture->unit->transactionCompleted);
                self::assertCount(1, $fixture->audit->all());
                $attempts[] = $event;
                if ($event instanceof CommandFailedEvent) {
                    throw new RuntimeException('Failure subscriber failure.');
                }

                throw $primary;
            }
        ));
        self::assertSame($primary, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
        $this->assertOutcome($fixture, $operation, true);
        self::assertCount(2, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
        self::assertSame(
            $operation === 'create' ? 'Feature creation failed.' : 'Feature removal failed.',
            $attempts[1]->toArray()['error_message']
        );
    }

    #[DataProvider('operations')]
    public function test_trusted_clock_failure_rolls_back_the_staged_mutation(string $operation): void
    {
        $primary = new RuntimeException('Outcome clock unavailable.');
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::once())->method('now')->willThrowException($primary);
        $fixture = new FeatureLifecycleAuditFixture($operation, clock: $clock);
        self::assertSame($primary, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
        $this->assertOutcome($fixture, $operation, false);
    }

    #[DataProvider('operations')]
    public function test_explicit_non_user_origins_remain_the_original_typed_actor(string $operation): void
    {
        foreach ([AgentId::generate(), SystemId::generate(), AnonymousId::generate()] as $actorId) {
            $fixture = new FeatureLifecycleAuditFixture($operation);
            $command = new RemoveFeature($fixture->original->getId(), 7, $actorId);
            if ($operation === 'create') {
                $command = new CreateFeature($fixture->original->getName(), $fixture->permission->getId(), $actorId);
            }

            $fixture->bus->execute($command);
            self::assertEquals($actorId, $fixture->audit->all()[0]->actorId());
        }
    }

    public function test_manual_creation_accepts_an_existing_protected_permission_without_promoting_or_granting(): void
    {
        $fixture = new FeatureLifecycleAuditFixture('create');
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('PROTECTED_FEATURE'),
            PermissionTier::SUPER_ADMIN_ONLY,
            $fixture->time
        );
        $fixture->permissions->add($permission);
        $fixture->bus->execute(new CreateFeature(
            $fixture->original->getName(),
            $permission->getId(),
            $fixture->command->getActorId()
        ));
        self::assertSame($permission, $fixture->permissions->getById($permission->getId()));
        self::assertSame(
            $permission->getId()->toString(),
            $fixture->audit->all()[0]->getFacts()->toArray()['after']['permission_id']
        );
    }

    public function test_new_creations_use_distinct_evidence_and_feature_identities(): void
    {
        $first = new FeatureLifecycleAuditFixture('create');
        $second = new FeatureLifecycleAuditFixture('create');
        $first->bus->execute($first->command);
        $second->bus->execute($second->command);
        self::assertFalse($first->audit->all()[0]->getId()->equals($second->audit->all()[0]->getId()));
        self::assertFalse($first->audit->all()[0]->getSubject()->getId()->equals(
            $second->audit->all()[0]->getSubject()->getId()
        ));
    }

    public function test_duplicate_creation_and_permission_race_keep_winners_without_evidence(): void
    {
        foreach (['duplicate', 'permission'] as $race) {
            $fixture = new FeatureLifecycleAuditFixture('create');
            if ($race === 'duplicate') {
                $fixture->features->beforeAdd = static function () use ($fixture): void {
                    $fixture->features->seed($fixture->original);
                };
            } else {
                $winner = new InMemoryPermissionRepository(
                    authorizationReferences: $fixture->unit->authorizationReferenceState()
                );
                $winner->add($fixture->permission);
                $fixture->features->beforeAdd = static function () use ($fixture, $winner): void {
                    self::assertTrue($winner->remove($fixture->permission));
                };
            }

            self::assertInstanceOf(
                StateConflict::class,
                $this->failure(fn() => $fixture->bus->execute($fixture->command))
            );
            self::assertSame([], $fixture->audit->all());
            self::assertSame(
                $race === 'duplicate' ? $fixture->original : null,
                $fixture->features->getByName($fixture->original->getName())
            );
            self::assertSame($race === 'duplicate', $fixture->unit->authorizationReferenceState()
                ->permissionsAreAuthoritative([$fixture->permission->getId()]));
        }
    }

    public function test_retirement_current_discovery_failures_never_establish_absence(): void
    {
        foreach (['reference', 'partial', 'candidate'] as $scan) {
            $discovery = new FixtureFeatureDiscovery(
                $scan === 'candidate' ? FeatureReferenceScope::CANDIDATE : FeatureReferenceScope::CURRENT,
                new class {
                },
                $scan === 'reference' ? ['dashboard'] : [],
                static fn(): bool => $scan !== 'partial'
            );
            $fixture = new FeatureLifecycleAuditFixture('remove', discovery: $discovery);
            $this->failure(fn() => $fixture->bus->execute($fixture->command));
            $this->assertOutcome($fixture, 'remove', false);
            self::assertSame(0, $fixture->features->writes);
        }
    }

    public function test_stale_and_recreated_targets_keep_current_state_without_evidence(): void
    {
        foreach (['revision', 'recreated', 'cas'] as $race) {
            $fixture = new FeatureLifecycleAuditFixture('remove');
            $winner = $fixture->original->withStatus(FeatureStatus::ON);
            if ($race === 'recreated') {
                $winner = Feature::define(
                    FeatureId::generate(),
                    $fixture->original->getName(),
                    $fixture->permission->getId()
                );
            }

            if ($race === 'cas') {
                $fixture->features->beforeRemove = static function () use ($fixture, $winner): void {
                    $fixture->features->seed($winner);
                };
            } elseif ($race === 'recreated') {
                $fixture->unit->commitTransactional(static function () use ($fixture, $winner): void {
                    self::assertTrue($fixture->features->remove($fixture->original));
                    $fixture->features->add($winner);
                });
            } else {
                $fixture->features->seed($winner);
            }

            self::assertInstanceOf(match ($race) {
                'revision' => PreconditionFailed::class,
                'recreated' => ResourceNotFound::class,
                default => StateConflict::class
            }, $this->failure(fn() => $fixture->bus->execute($fixture->command)));
            self::assertSame($winner, $fixture->features->getByName($winner->getName()));
            self::assertSame([], $fixture->audit->all());
        }
    }

    public function test_broken_binding_retirement_records_prior_relationship_without_permission_lookup(): void
    {
        $fixture = new FeatureLifecycleAuditFixture('remove');
        $fixture->unit->authorizationReferenceState()->removeFeature($fixture->original->getId());
        self::assertTrue($fixture->permissions->remove($fixture->permission));
        $fixture->features->seed($fixture->original);
        $fixture->bus->execute($fixture->command);
        self::assertNull($fixture->features->getById($fixture->original->getId()));
        self::assertSame(
            $fixture->permission->getId()->toString(),
            $fixture->audit->all()[0]->getFacts()->toArray()['before']['permission_id']
        );
        self::assertNull($fixture->permissions->getById($fixture->permission->getId()));
    }

    private function assertOutcome(FeatureLifecycleAuditFixture $fixture, string $operation, bool $committed): void
    {
        $current = $fixture->features->getByName($fixture->original->getName());
        $exists = $operation === 'create' ? $committed : !$committed;
        self::assertSame($exists, $current !== null);
        if (!$committed && $operation === 'remove') {
            self::assertSame($fixture->original, $current);
        }

        self::assertCount((int) $committed, $fixture->audit->all());
        $this->assertPermissionFence($fixture, $exists);
    }

    private function assertPermissionFence(FeatureLifecycleAuditFixture $fixture, bool $referenced): void
    {
        self::assertSame(!$referenced, $fixture->permissions->remove($fixture->permission));
        if (!$referenced) {
            $fixture->permissions->add($fixture->permission);
        }
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected failure.');
    }
}
