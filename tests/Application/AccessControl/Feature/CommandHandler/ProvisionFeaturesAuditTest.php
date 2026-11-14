<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Identifier\Uuid;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FailingClock;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ProvisionFeaturesHandler::class)]
final class ProvisionFeaturesAuditTest extends TestCase
{
    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryFeatureRepository $featureRepository;

    private InMemoryPermissionRepository $permissionRepository;

    private MappedAuditEvidenceRepository $auditRepository;

    private InMemoryEventDispatcher $eventDispatcher;

    private FixedClock $clock;

    private Permission $permission;

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->featureRepository = new InMemoryFeatureRepository($this->unitOfWork);
        $this->permissionRepository = new InMemoryPermissionRepository($this->unitOfWork);
        $this->auditRepository = new MappedAuditEvidenceRepository($this->unitOfWork);
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->clock = new FixedClock('2026-11-20T12:00:00.123456+00:00', '2026-11-20T12:00:01.654321+00:00');
        $this->permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('TEST_FEATURES'),
            new DateTimeImmutable('2026-01-01')
        );
        $this->permissionRepository->add($this->permission);
    }

    #[DataProvider('actors')]
    public function test_created_subjects_share_origin_and_real_correlation(string $kind): void
    {
        $actor = match ($kind) {
            'user' => UserId::generate(),
            'agent' => AgentId::generate(),
            'system' => SystemId::fromString(Uuid::NIL),
            'anonymous' => AnonymousId::fromString(Uuid::NIL)
        };
        $existing = $this->existing('existing');
        $message = CommandMessage::create(new ProvisionFeatures('TEST_FEATURES', $actor));
        $this->eventDispatcher = new InMemoryEventDispatcher(function (): void {
            self::assertFalse($this->unitOfWork->transactionActive);
            self::assertCount(2, $this->auditRepository->all());
        });

        $this->handler(['first', 'existing', 'second', 'first'])->handle($message);

        self::assertSame($existing, $this->featureRepository->getById($existing->getId()));
        self::assertSame(1, $this->unitOfWork->transactions);
        self::assertSame(2, $this->clock->calls());
        $records = $this->auditRepository->all();
        self::assertCount(2, $records);
        self::assertNotSame($records[0]->getId()->toString(), $records[1]->getId()->toString());
        foreach ($records as $index => $record) {
            $feature = $this->featureRepository->getByName(FeatureName::fromString(['first', 'second'][$index]));
            self::assertInstanceOf(Feature::class, $feature);
            self::assertSame([
                'actor'       => ['kind' => $kind, 'id' => $actor->toString()],
                'subject'     => ['kind' => 'feature', 'id' => $feature->getId()->toString()],
                'action'      => 'feature.created',
                'occurred_at' => ['2026-11-20T12:00:00.123456+00:00', '2026-11-20T12:00:01.654321+00:00'][$index],
                'correlation' => ['message_id' => $message->id()->toString(), 'operation_key' => null],
                'facts'       => ['before' => null, 'after' => [
                    'revision' => 1, 'status' => 'off', 'permission_id' => $this->permission->getId()->toString()
                ]]
            ], array_diff_key($record->toArray(), ['id' => true]));
            self::assertNotSame(Uuid::NIL, $record->getId()->toString());
            self::assertSame($actor::class, $record->actorId()::class);
        }
    }

    public static function actors(): iterable
    {
        foreach (['user', 'agent', 'system', 'anonymous'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function test_enabled_no_change_requires_neither_actor_nor_default_or_clock_and_never_backfills(): void
    {
        $stored = $this->existing('existing');
        foreach ([[], ['existing']] as $names) {
            foreach ([null, '', 'invalid', 'UNDEFINED_PERMISSION'] as $configuration) {
                $this->handler($names)->handle(CommandMessage::create(new ProvisionFeatures($configuration)));
            }
        }

        self::assertSame([$stored->getId()->toString() => $stored], $this->featureRepository->records);
        self::assertSame([], $this->auditRepository->all());
        self::assertSame([], $this->eventDispatcher->events());
        self::assertSame(0, $this->clock->calls());
    }

    public function test_explicit_null_and_omitted_audit_allow_actorless_creation_without_clock_use(): void
    {
        foreach ([false, true] as $explicitNull) {
            $discovery = new FixtureFeatureDiscovery(FeatureReferenceScope::CANDIDATE, new class {
            }, [$explicitNull ? 'second' : 'first'], static fn(): bool => true);
            $arguments = [
                $discovery, $this->featureRepository, $this->permissionRepository, $this->clock,
                $this->unitOfWork, $this->eventDispatcher
            ];
            if ($explicitNull) {
                $arguments[] = null;
            }

            new ProvisionFeaturesHandler(...$arguments)->handle(
                CommandMessage::create(new ProvisionFeatures('TEST_FEATURES'))
            );
        }

        self::assertCount(2, $this->featureRepository->records);
        self::assertCount(2, $this->eventDispatcher->events());
        self::assertSame([], $this->auditRepository->all());
        self::assertSame(0, $this->clock->calls());
    }

    public function test_missing_origin_rejects_before_first_mutation_in_a_mixed_batch(): void
    {
        $stored = $this->existing('existing');
        $failure = $this->fails(fn() => $this->handler(['existing', 'first'])->handle(
            CommandMessage::create(new ProvisionFeatures('TEST_FEATURES'))
        ));

        self::assertInstanceOf(RuleViolation::class, $failure);
        self::assertSame(['existing', 'first'], $this->featureRepository->lookups);
        self::assertSame([$stored->getId()->toString() => $stored], $this->featureRepository->records);
        self::assertSame(0, $this->featureRepository->writes);
        self::assertSame([], $this->auditRepository->all());
        self::assertSame(0, $this->clock->calls());
    }

    public function test_clock_failure_prevents_creation_or_evidence_commit(): void
    {
        $primary = new RuntimeException('Synthetic clock failure.');

        self::assertSame($primary, $this->fails(fn() => $this->handler(clock: new FailingClock($primary))
            ->handle($this->message())));
        self::assertSame([], $this->featureRepository->records);
        self::assertSame([], $this->auditRepository->all());
        self::assertCount(1, $this->eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $this->eventDispatcher->events()[0]);
    }

    #[DataProvider('failures')]
    public function test_batch_faults_preserve_primary_state_evidence_and_authoritative_retry(string $boundary): void
    {
        $primary = new RuntimeException('Synthetic primary failure.');
        $winner = null;
        $audit = $this->auditRepository;
        if ($boundary === 'audit-before' || $boundary === 'audit-after') {
            $audit = $this->createStub(AuditEvidenceRepository::class);
            $audit->method('add')->willReturnCallback(function (AuditEvidence $evidence) use (
                $boundary,
                $primary
            ): void {
                if ($boundary === 'audit-after') {
                    $this->auditRepository->add($evidence);
                }

                if (count($this->featureRepository->records) === 2) {
                    throw $primary;
                }

                if ($boundary === 'audit-before') {
                    $this->auditRepository->add($evidence);
                }
            });
        } elseif (in_array($boundary, ['insert', 'reference', 'name-winner', 'id-winner'], true)) {
            $this->featureRepository->beforeAdd = function (Feature $feature) use (
                $boundary,
                $primary,
                &$winner
            ): void {
                if ($feature->getName()->toString() !== 'second') {
                    return;
                }

                if ($boundary === 'insert') {
                    throw $primary;
                }

                if ($boundary === 'reference') {
                    $this->unitOfWork->authorizationReferenceState()->removePermission($feature->getPermissionId());
                } else {
                    $winner = Feature::reconstitute(
                        $boundary === 'id-winner' ? $feature->getId() : FeatureId::generate(),
                        FeatureName::fromString($boundary === 'id-winner' ? 'unregistered' : 'second'),
                        $this->permission->getId(),
                        FeatureStatus::ON,
                        7
                    );
                    $this->featureRepository->seed($winner);
                }
            };
        } elseif ($boundary === 'commit' || $boundary === 'uncertain-rollback') {
            $this->unitOfWork->beforeCommit = static function () use ($primary): void {
                throw $primary;
            };
        } elseif ($boundary === 'uncertain-commit') {
            $this->unitOfWork->afterCommit = static function () use ($primary): void {
                throw $primary;
            };
        }

        $attempts = 0;
        $failures = [];
        $this->eventDispatcher = new InMemoryEventDispatcher(function (Event $event) use (
            $boundary,
            $primary,
            &$attempts,
            &$failures
        ): void {
            if ($event instanceof CommandFailedEvent) {
                $failures[] = $event->toArray();
                throw new RuntimeException('Synthetic secondary notification failure.');
            }

            self::assertInstanceOf(FeatureCreated::class, $event);
            self::assertCount(2, $this->auditRepository->all());
            if (++$attempts === ($boundary === 'publish-later' ? 2 : 1)) {
                throw $primary;
            }
        });
        $message = $this->message();
        $caught = $this->fails(fn() => $this->handler(audit: $audit)->handle($message));
        if (in_array($boundary, ['reference', 'name-winner', 'id-winner'], true)) {
            self::assertInstanceOf(StateConflict::class, $caught);
        } else {
            self::assertSame($primary, $caught);
        }

        $committed = in_array($boundary, ['uncertain-commit', 'publish-first', 'publish-later'], true);
        self::assertCount($committed ? 2 : (int) ($winner !== null), $this->featureRepository->records);
        self::assertCount($committed ? 2 : 0, $this->auditRepository->all());
        self::assertCount(1, $failures);
        self::assertStringNotContainsString('Synthetic', json_encode($failures, JSON_THROW_ON_ERROR));
        self::assertFalse($this->unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
        $retained = array_map(
            static fn(AuditEvidence $record): array => $record->toArray(),
            $this->auditRepository->all()
        );

        $this->featureRepository->beforeAdd = null;
        $this->unitOfWork->beforeCommit = null;
        $this->unitOfWork->afterCommit = null;

        $this->permissionRepository->add($this->permission);
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->handler()->handle($this->message());

        $newCount = $committed ? 0 : ($boundary === 'name-winner' ? 1 : 2);
        self::assertCount($boundary === 'name-winner' ? 1 : 2, $this->auditRepository->all());
        self::assertCount($newCount, $this->eventDispatcher->events());
        if ($committed) {
            self::assertSame($retained, array_map(
                static fn(AuditEvidence $record): array => $record->toArray(),
                $this->auditRepository->all()
            ));
        }

        if ($winner !== null) {
            self::assertSame($winner, $this->featureRepository->getById($winner->getId()));
        }
    }

    public static function failures(): iterable
    {
        foreach (
            [
            'audit-before', 'audit-after', 'insert', 'reference', 'name-winner', 'id-winner', 'commit',
            'uncertain-rollback', 'uncertain-commit', 'publish-first', 'publish-later'
            ] as $boundary
        ) {
            yield $boundary => [$boundary];
        }
    }

    private function existing(string $name): Feature
    {
        $feature = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString($name),
            PermissionId::generate(),
            FeatureStatus::PREVIEW,
            9
        );
        $this->featureRepository->seed($feature);

        return $feature;
    }

    private function message(): CommandMessage
    {
        return CommandMessage::create(new ProvisionFeatures('TEST_FEATURES', SystemId::fromString(Uuid::NIL)));
    }

    private function handler(
        array $names = ['first', 'second'],
        ?AuditEvidenceRepository $audit = null,
        ?Clock $clock = null
    ): ProvisionFeaturesHandler {
        return new ProvisionFeaturesHandler(
            new FixtureFeatureDiscovery(FeatureReferenceScope::CANDIDATE, new class {
            }, $names, static fn(): bool => true),
            $this->featureRepository,
            $this->permissionRepository,
            $clock ?? $this->clock,
            $this->unitOfWork,
            $this->eventDispatcher,
            $audit ?? $this->auditRepository
        );
    }

    private function fails(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected operation failure.');
    }
}
