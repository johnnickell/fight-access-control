<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fiber;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeaturePermissionHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeaturePermissionChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(SetFeatureStatusHandler::class)]
#[CoversClass(SetFeaturePermissionHandler::class)]
final class FeatureSettingAuditTest extends TestCase
{
    private InMemoryUnitOfWork $unit;

    private InMemoryFeatureRepository $features;

    private InMemoryPermissionRepository $permissions;

    private InMemoryAuditEvidenceRepository $audit;

    private InMemoryEventDispatcher $events;

    private FixedClock $clock;

    private Feature $feature;

    private Feature $unrelated;

    private Permission $selected;

    protected function setUp(): void
    {
        $this->unit = new InMemoryUnitOfWork();
        $this->features = new InMemoryFeatureRepository($this->unit);
        $this->permissions = new InMemoryPermissionRepository($this->unit);
        $this->audit = new InMemoryAuditEvidenceRepository($this->unit);
        $this->events = new InMemoryEventDispatcher();
        $this->clock = new FixedClock('2026-11-13T12:34:56.123456+00:00');
        $original = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('ORIGINAL'),
            $this->clock->now()
        );
        $this->selected = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('SELECTED'),
            PermissionTier::SUPER_ADMIN_ONLY,
            $this->clock->now()
        );
        $this->permissions->add($original);
        $this->permissions->add($this->selected);

        $this->feature = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('dashboard'),
            $original->getId()
        );
        $this->unrelated = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('unrelated'),
            $original->getId()
        );
        $this->features->seed($this->feature);
        $this->features->seed($this->unrelated);
    }

    /** @return iterable<string, array{bool}> */
    public static function actions(): iterable
    {
        yield 'status' => [false];
        yield 'binding' => [true];
    }

    #[DataProvider('actions')]
    public function test_evidence_joins_authoritative_write_fence_and_survives_publication(bool $binding): void
    {
        $actor = UserId::generate();
        $message = $this->message($binding, $actor);
        $writer = $this->createMock(AuditEvidenceRepository::class);
        $writer->expects(self::once())->method('add')->willReturnCallback(function (AuditEvidence $evidence): void {
            self::assertTrue($this->unit->transactionActive);
            self::assertTrue($this->unit->authorizationReferenceState()->isReferenceFenceHeld());
            self::assertSame(2, $this->features->getById($this->feature->getId())?->getRevision());
            $current = $this->features->getById($this->feature->getId());
            $bound = $this->permissions->getById($current->getPermissionId());
            self::assertFalse($this->permissions->remove($bound));
            $this->audit->add($evidence);
        });
        $failure = new RuntimeException('publication diagnostic');
        $this->events = new InMemoryEventDispatcher(function (object $event) use ($failure): void {
            if ($event instanceof FeatureStatusChanged || $event instanceof FeaturePermissionChanged) {
                self::assertTrue($this->unit->transactionCompleted);
                self::assertCount(1, $this->audit->all());
                throw $failure;
            }

            throw new RuntimeException('secondary notification diagnostic');
        });
        self::assertSame($failure, $this->failure(fn() => $this->handler($binding, $writer)->handle($message)));
        $record = $this->audit->all()[0];
        self::assertSame($actor, $record->actorId());
        self::assertTrue($record->getSubject()->getId()->equals($this->feature->getId()));
        self::assertSame(
            $binding ? AuditAction::FEATURE_PERMISSION_CHANGED : AuditAction::FEATURE_STATUS_CHANGED,
            $record->action()
        );
        self::assertSame('2026-11-13T12:34:56.123456+00:00', $record->getOccurredAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(
            ['message_id' => $message->id()->toString(), 'operation_key' => null],
            $record->getCorrelation()?->toArray()
        );
        self::assertSame([
            'before' => [
                'revision'      => 1,
                'status'        => 'off',
                'permission_id' => $this->feature->getPermissionId()->toString()
            ],
            'after'  => [
                'revision'      => 2,
                'status'        => $binding ? 'off' : 'on',
                'permission_id' => ($binding ? $this->selected->getId() : $this->feature->getPermissionId())->toString()
            ]
        ], $record->getFacts()->toArray());
        self::assertSame($record->toArray(), AuditEvidence::fromArray($record->toArray())->toArray());
        self::assertSame($this->unrelated, $this->features->getById($this->unrelated->getId()));
        self::assertSame(2, $this->features->getById($this->feature->getId())?->getRevision());
    }

    #[DataProvider('actions')]
    public function test_disabled_and_validated_no_change_need_no_new_actor(bool $binding): void
    {
        $this->handler($binding, null)->handle($this->message($binding, null));
        self::assertSame(2, $this->features->getById($this->feature->getId())?->getRevision());
        self::assertSame([], $this->audit->all());
        $before = $this->features->getById($this->feature->getId());
        $this->handler($binding, $this->audit)->handle($this->message($binding, null, true, 2));
        self::assertSame($before, $this->features->getById($this->feature->getId()));
        self::assertSame(1, $this->features->writes);
        self::assertCount(1, $this->events->events());
        self::assertSame([], $this->audit->all());
        self::assertInstanceOf(PreconditionFailed::class, $this->failure(
            fn() => $this->handler($binding, $this->audit)->handle($this->message($binding, null, true, 1))
        ));
    }

    #[DataProvider('actions')]
    public function test_enabled_changed_work_admits_actor_before_persistence(bool $binding): void
    {
        self::assertInstanceOf(InvalidInput::class, $this->failure(
            fn() => $this->handler($binding, $this->audit)->handle($this->message($binding, null))
        ));
        self::assertSame(0, $this->features->writes);
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertSame([], $this->audit->all());
    }

    #[DataProvider('actions')]
    public function test_audit_failure_rolls_back_staged_state_and_record_preserving_primary(bool $binding): void
    {
        $audit = new InMemoryAuditEvidenceRepository($this->unit, true);
        $this->events = new InMemoryEventDispatcher(static function (): void {
            throw new RuntimeException('secondary');
        });
        $message = $this->message($binding, UserId::generate());
        $failure = $this->failure(fn() => $this->handler($binding, $audit)->handle($message));
        self::assertSame($audit->failure(), $failure);
        self::assertSame([], $audit->all());
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertSame($this->unrelated, $this->features->getById($this->unrelated->getId()));
        self::assertFalse($this->unit->transactionCompleted);
    }

    #[DataProvider('actions')]
    public function test_commit_loss_rolls_back_but_lost_acknowledgement_retains_both(bool $binding): void
    {
        $this->unit->failNextCommit = true;
        $message = $this->message($binding, UserId::generate());
        $this->failure(fn() => $this->handler($binding, $this->audit)->handle($message));
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertSame([], $this->audit->all());
        $uncertain = new RuntimeException('commit acknowledgement lost');
        $this->unit->afterCommit = static function () use ($uncertain): void {
            throw $uncertain;
        };
        self::assertSame($uncertain, $this->failure(
            fn() => $this->handler($binding, $this->audit)->handle($this->message($binding, UserId::generate()))
        ));
        self::assertSame(2, $this->features->getById($this->feature->getId())?->getRevision());
        self::assertCount(1, $this->audit->all());
        $this->unit->afterCommit = null;
        $this->handler($binding, $this->audit)->handle($this->message($binding, null, true, 2));
        self::assertCount(1, $this->audit->all());
        foreach ($this->events->events() as $event) {
            self::assertInstanceOf(CommandFailedEvent::class, $event);
        }
    }

    #[DataProvider('actions')]
    public function test_competing_other_field_winner_is_not_undone_or_audited(bool $binding): void
    {
        $winner = $this->feature->withPermission($this->selected->getId());
        if ($binding) {
            $winner = $this->feature->withStatus(FeatureStatus::PREVIEW);
        }

        $this->features->beforeReplace = function (): void {
            $this->unit->suspendBeforeFirstWrite();
        };
        $loser = new Fiber(fn(): Throwable => $this->failure(
            fn() => $this->handler($binding, $this->audit)->handle($this->message($binding, UserId::generate()))
        ));
        $loser->start();

        $this->features->seed($winner);
        $loser->resume();
        self::assertInstanceOf(StateConflict::class, $loser->getReturn());
        self::assertSame($winner, $this->features->getById($winner->getId()));
        self::assertSame([], $this->audit->all());
        self::assertSame($this->unrelated, $this->features->getById($this->unrelated->getId()));
    }

    #[DataProvider('actions')]
    public function test_reference_loss_and_broken_binding_cannot_be_laundered(bool $binding): void
    {
        $this->features->beforeReplace = function (Feature $expected, Feature $replacement): void {
            $this->unit->authorizationReferenceState()->removePermission($replacement->getPermissionId());
        };
        self::assertInstanceOf(StateConflict::class, $this->failure(
            fn() => $this->handler($binding, $this->audit)->handle($this->message($binding, UserId::generate()))
        ));
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertSame([], $this->audit->all());
    }

    public function test_existing_selected_identity_repairs_broken_binding_with_truthful_evidence(): void
    {
        $broken = Feature::reconstitute(
            $this->feature->getId(),
            $this->feature->getName(),
            PermissionId::generate(),
            FeatureStatus::PREVIEW,
            1
        );
        $this->features->seed($broken);
        self::assertInstanceOf(StateConflict::class, $this->failure(
            fn() => $this->handler(false, $this->audit)->handle($this->message(false, UserId::generate()))
        ));
        self::assertSame([], $this->audit->all());
        $this->handler(true, $this->audit)->handle($this->message(true, UserId::generate()));
        $facts = $this->audit->all()[0]->getFacts()->toArray();
        self::assertSame($broken->getPermissionId()->toString(), $facts['before']['permission_id']);
        self::assertSame($this->selected->getId()->toString(), $facts['after']['permission_id']);
        self::assertSame('preview', $facts['after']['status']);
        self::assertSame(2, $facts['after']['revision']);
    }

    #[DataProvider('actions')]
    public function test_noop_still_checks_authoritative_reference_without_actor(bool $binding): void
    {
        $this->unit->authorizationReferenceState()->removePermission($this->feature->getPermissionId());
        self::assertInstanceOf(StateConflict::class, $this->failure(
            fn() => $this->handler($binding, $this->audit)->handle($this->message($binding, null, true))
        ));
        self::assertSame([], $this->audit->all());
        self::assertSame(0, $this->features->writes);
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
    }

    private function handler(
        bool $binding,
        ?AuditEvidenceRepository $audit
    ): SetFeatureStatusHandler|SetFeaturePermissionHandler {
        if ($binding) {
            return new SetFeaturePermissionHandler(
                $this->features,
                $this->permissions,
                $this->clock,
                $this->unit,
                $this->events,
                $audit
            );
        }

        return new SetFeatureStatusHandler($this->features, $this->clock, $this->unit, $this->events, $audit);
    }

    private function message(bool $binding, ?UserId $actor, bool $same = false, int $revision = 1): CommandMessage
    {
        $current = $this->features->getById($this->feature->getId());

        if ($binding) {
            return CommandMessage::create(new SetFeaturePermission(
                $this->feature->getId(),
                $same ? $current->getPermissionId() : $this->selected->getId(),
                $revision,
                $actor
            ));
        }

        return CommandMessage::create(new SetFeatureStatus(
            $this->feature->getId(),
            $same ? $current->getStatus() : FeatureStatus::ON,
            $revision,
            $actor
        ));
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected operation failure.');
    }
}
