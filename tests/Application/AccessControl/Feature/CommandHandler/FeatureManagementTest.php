<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeaturePermissionHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\GetFeatureByIdHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ListFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeaturePermissionChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Service\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(CreateFeatureHandler::class)]
#[CoversClass(SetFeatureStatusHandler::class)]
#[CoversClass(SetFeaturePermissionHandler::class)]
#[CoversClass(GetFeatureByIdHandler::class)]
#[CoversClass(ListFeaturesHandler::class)]
#[CoversClass(FeatureAvailability::class)]
#[CoversClass(Feature::class)]
#[CoversClass(CreateFeature::class)]
#[CoversClass(SetFeaturePermission::class)]
#[CoversClass(SetFeatureStatus::class)]
#[CoversClass(FeatureCreated::class)]
#[CoversClass(FeaturePermissionChanged::class)]
#[CoversClass(FeatureStatusChanged::class)]
#[CoversClass(FeatureView::class)]
#[CoversClass(GetFeatureById::class)]
#[CoversClass(ListFeatures::class)]
final class FeatureManagementTest extends TestCase
{
    private InMemoryUnitOfWork $uow;

    private InMemoryFeatureRepository $features;

    private InMemoryPermissionRepository $permissions;

    private InMemoryEventDispatcher $events;

    private Permission $first;

    private Permission $second;

    protected function setUp(): void
    {
        $this->uow = new InMemoryUnitOfWork();
        $this->features = new InMemoryFeatureRepository($this->uow);
        $this->permissions = new InMemoryPermissionRepository($this->uow);
        $this->events = new InMemoryEventDispatcher();
        $time = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->first = Permission::define(PermissionId::generate(), PermissionName::fromString('FIRST'), $time);
        $this->second = Permission::define(PermissionId::generate(), PermissionName::fromString('SECOND'), $time);
        $this->permissions->add($this->first);
        $this->permissions->add($this->second);
    }

    public function test_create_read_provision_preservation_and_paginated_broken_binding(): void
    {
        $this->create('dashboard');
        $stored = $this->features->getByName(FeatureName::fromString('dashboard'));
        self::assertInstanceOf(Feature::class, $stored);
        self::assertSame(FeatureStatus::OFF, $stored->getStatus());
        self::assertSame(1, $stored->getRevision());
        self::assertInstanceOf(FeatureCreated::class, $this->events->events()[0]);
        $this->features->seed(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('broken'),
            PermissionId::generate(),
            FeatureStatus::ON,
            4
        ));

        $view = new GetFeatureByIdHandler($this->features, $this->permissions)
            ->handle(QueryMessage::create(new GetFeatureById($stored->getId())));
        self::assertSame('FIRST', $view->getPermissionName()?->toString());
        self::assertSame(1, $view->getRevision());
        self::assertFalse($view->isBindingMissing());
        $page = new ListFeaturesHandler($this->features, $this->permissions)
            ->handle(QueryMessage::create(new ListFeatures(new Pagination(2, 1, []))));
        self::assertSame(2, $page->page());
        self::assertSame(2, $page->totalRecords());
        self::assertCount(1, $page->records());
        $broken = $page->records()->first();
        self::assertTrue($broken->isBindingMissing());
        self::assertNull($broken->getPermissionName());
        self::assertSame(4, $broken->toArray()['revision']);
        self::assertSame('on', $broken->toArray()['status']);
        self::assertSame(1, $this->features->writes);
        self::assertSame($stored, $this->features->getByName(FeatureName::fromString('dashboard')));
    }

    public function test_status_and_binding_edits_share_revision_and_fresh_evaluation(): void
    {
        $feature = $this->create('dashboard');
        $availability = new FeatureAvailability($this->features, $this->permissions);
        $name = $feature->getName();
        self::assertFalse($availability->isAvailable($name, null));
        $this->changeStatus($feature, FeatureStatus::PREVIEW, 1);
        self::assertFalse($availability->isAvailable($name, null));
        $this->binding($feature, $this->second->getId(), 2);
        $this->changeStatus($feature, FeatureStatus::ON, 3);
        self::assertTrue($availability->isAvailable($name, null));
        $current = $this->features->getById($feature->getId());
        self::assertSame($this->second->getId(), $current?->getPermissionId());
        self::assertSame(4, $current->getRevision());
        self::assertCount(4, $this->events->events());
        self::assertInstanceOf(FeatureStatusChanged::class, $this->events->events()[1]);
        self::assertInstanceOf(FeaturePermissionChanged::class, $this->events->events()[2]);
        $this->changeStatus($feature, FeatureStatus::ON, 4);
        $this->binding($feature, $this->second->getId(), 4);
        self::assertCount(4, $this->events->events());
        self::assertSame(4, $this->features->writes);
        $this->changeStatus($feature, FeatureStatus::OFF, 4);
        self::assertFalse($availability->isAvailable($name, null));
    }

    public function test_manual_creation_survives_real_provisioning_with_changed_default(): void
    {
        $created = $this->create('dashboard');
        $this->binding($created, $this->second->getId(), 1);
        $this->changeStatus($created, FeatureStatus::PREVIEW, 2);
        $before = $this->features->getById($created->getId());
        $discovery = new FixtureFeatureDiscovery(new class {
        }, ['dashboard', 'new-checkout'], static fn(): bool => true);
        new ProvisionFeaturesHandler($discovery, $this->features, $this->permissions, $this->uow, $this->events)
            ->handle(CommandMessage::create(new ProvisionFeatures('FIRST')));
        self::assertSame($before, $this->features->getById($created->getId()));
        self::assertSame(3, $before->getRevision());
        self::assertSame($this->second->getId(), $before->getPermissionId());
        $new = $this->features->getByName(FeatureName::fromString('new-checkout'));
        self::assertSame(FeatureStatus::OFF, $new->getStatus());
    }

    public function test_real_binding_changes_preview_cohort_for_same_principal_snapshot(): void
    {
        $feature = $this->create('dashboard');
        $name = $feature->getName();
        $principal = new AuthenticatedAgentPrincipal(AgentId::generate(), AgentCredentialId::generate(), 1, 1, [
            new PrincipalPermission($this->first->getId(), $this->first->getName())
        ]);
        $availability = new FeatureAvailability($this->features, $this->permissions);
        $this->changeStatus($feature, FeatureStatus::PREVIEW, 1);
        self::assertTrue($availability->isAvailable($name, $principal));
        $this->binding($feature, $this->second->getId(), 2);
        self::assertFalse($availability->isAvailable($name, $principal));
        $this->binding($feature, $this->first->getId(), 3);
        self::assertTrue($availability->isAvailable($name, $principal));
    }

    public function test_stale_noop_and_conflicting_binding_reject_without_lost_update(): void
    {
        $feature = $this->create('dashboard');
        $this->changeStatus($feature, FeatureStatus::ON, 1);
        $failure = $this->fails(fn() => $this->binding($feature, $this->second->getId(), 1));
        self::assertInstanceOf(FeatureRevisionException::class, $failure);
        $failure = $this->fails(fn() => $this->changeStatus($feature, FeatureStatus::ON, 1));
        self::assertInstanceOf(FeatureRevisionException::class, $failure);
        self::assertSame(2, $this->features->getById($feature->getId())->getRevision());
        self::assertSame($this->first->getId(), $this->features->getById($feature->getId())?->getPermissionId());
    }

    public function test_broken_binding_can_only_be_repaired_with_an_existing_identity(): void
    {
        $feature = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('broken'),
            PermissionId::generate(),
            FeatureStatus::PREVIEW,
            3
        );
        $this->features->seed($feature);
        self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
            fn() => $this->changeStatus($feature, FeatureStatus::ON, 3)
        ));
        $this->binding($feature, $this->first->getId(), 3);
        self::assertSame(4, $this->features->getById($feature->getId())->getRevision());
        self::assertSame($this->first->getId(), $this->features->getById($feature->getId())?->getPermissionId());
    }

    public function test_reference_fence_rejects_conflicting_removal_and_validates_noop(): void
    {
        $feature = $this->create('dashboard');
        $this->features->beforeReplace = function (): void {
            self::assertFalse($this->permissions->remove($this->first));
        };
        $this->binding($feature, $this->first->getId(), 1);
        $this->features->beforeReplace = null;
        self::assertTrue($this->permissions->remove($this->second));
        self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
            fn() => $this->binding($feature, $this->second->getId(), 1)
        ));
        self::assertSame($this->first->getId(), $this->features->getById($feature->getId())?->getPermissionId());
        self::assertTrue($this->permissions->hasFeatureReference($this->first->getId()));
    }

    public function test_failed_transaction_and_publication_keep_correct_state(): void
    {
        $feature = $this->create('dashboard');
        $this->uow->failNextCommit = true;
        $this->fails(fn() => $this->changeStatus($feature, FeatureStatus::ON, 1));
        self::assertSame(FeatureStatus::OFF, $this->features->getById($feature->getId())?->getStatus());
        self::assertSame(1, $this->features->getById($feature->getId())->getRevision());
        self::assertCount(2, $this->events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $this->events->events()[1]);
        $this->events = new InMemoryEventDispatcher(static function (object $event): void {
            if ($event instanceof FeatureStatusChanged) {
                throw new RuntimeException('Post-commit publication failed.');
            }
        });
        $this->fails(fn() => $this->changeStatus($feature, FeatureStatus::ON, 1));
        self::assertSame(FeatureStatus::ON, $this->features->getById($feature->getId())?->getStatus());
    }

    public function test_missing_targets_invalid_selection_and_final_boundary_race_reject_safely(): void
    {
        self::assertSame(CreateFeature::class, CreateFeatureHandler::commandRegistration());
        self::assertSame(SetFeatureStatus::class, SetFeatureStatusHandler::commandRegistration());
        self::assertSame(SetFeaturePermission::class, SetFeaturePermissionHandler::commandRegistration());
        self::assertSame(GetFeatureById::class, GetFeatureByIdHandler::queryRegistration());
        self::assertSame(ListFeatures::class, ListFeaturesHandler::queryRegistration());
        $createMissing = new CreateFeature(FeatureName::fromString('missing-binding'), PermissionId::generate());
        self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
            function () use ($createMissing): void {
                new CreateFeatureHandler($this->features, $this->permissions, $this->uow, $this->events)
                    ->handle(CommandMessage::create($createMissing));
            }
        ));
        self::assertNull($this->features->getByName($createMissing->getName()));

        $unknown = Feature::define(FeatureId::generate(), FeatureName::fromString('unknown'), $this->first->getId());
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn() => $this->changeStatus($unknown, FeatureStatus::ON, 1)
        ));
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn() => $this->binding($unknown, $this->first->getId(), 1)
        ));
        self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
            fn() => $this->binding($this->create('dashboard'), PermissionId::generate(), 1)
        ));
        $feature = $this->features->getByName(FeatureName::fromString('dashboard'));
        self::assertInstanceOf(Feature::class, $feature);
        $this->features->beforeReplace = function () use ($feature): void {
            $this->features->seed($feature->withStatus(FeatureStatus::PREVIEW));
        };
        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            fn() => $this->changeStatus($feature, FeatureStatus::ON, 1)
        ));
        $this->features->beforeReplace = null;
        self::assertSame(FeatureStatus::PREVIEW, $this->features->getById($feature->getId())->getStatus());
        $this->features->seed($feature);
        $this->features->beforeReplace = function () use ($feature): void {
            $this->features->seed($feature->withStatus(FeatureStatus::ON));
        };
        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            fn() => $this->binding($feature, $this->second->getId(), 1)
        ));
        $this->features->beforeReplace = null;
    }

    public function test_safe_messages_and_facts_round_trip_without_guessing_required_data(): void
    {
        $featureId = FeatureId::generate();
        $name = FeatureName::fromString('dashboard');
        $permissionId = $this->first->getId();
        $messages = [
            new CreateFeature($name, $permissionId),
            new SetFeatureStatus($featureId, FeatureStatus::ON, 3),
            new SetFeaturePermission($featureId, $permissionId, 3),
            new FeatureStatusChanged($featureId, FeatureStatus::PREVIEW, 4),
            new FeaturePermissionChanged($featureId, $permissionId, 4),
            new GetFeatureById($featureId),
            new ListFeatures(new Pagination(2, 5, []))
        ];
        foreach ($messages as $message) {
            self::assertSame($message->toArray(), $message::fromArray($message->toArray())->toArray());
        }

        self::assertSame($name, $messages[0]->getName());
        self::assertSame($permissionId, $messages[0]->getPermissionId());
        self::assertSame($featureId, $messages[1]->getFeatureId());
        self::assertSame(FeatureStatus::ON, $messages[1]->getStatus());
        self::assertSame(3, $messages[1]->getExpectedRevision());
        self::assertSame($featureId, $messages[2]->getFeatureId());
        self::assertSame($permissionId, $messages[2]->getPermissionId());
        self::assertSame(3, $messages[2]->getExpectedRevision());
        self::assertSame(4, $messages[3]->getRevision());
        self::assertSame(FeatureStatus::PREVIEW, $messages[3]->getStatus());
        self::assertSame($featureId, $messages[3]->getFeatureId());
        self::assertSame(4, $messages[4]->getRevision());
        self::assertSame($permissionId, $messages[4]->getPermissionId());
        self::assertSame($featureId, $messages[4]->getFeatureId());
        self::assertSame($featureId, $messages[5]->getFeatureId());
        self::assertSame(2, $messages[6]->getPagination()->page());

        foreach ($messages as $message) {
            self::assertInstanceOf(Throwable::class, $this->fails(
                static function () use ($message): void {
                    $message::fromArray([]);
                }
            ));
        }

        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            static fn(): SetFeatureStatus => new SetFeatureStatus($featureId, FeatureStatus::OFF, 0)
        ));
        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            static fn(): SetFeaturePermission => new SetFeaturePermission($featureId, $permissionId, 0)
        ));
        self::assertInstanceOf(Throwable::class, $this->fails(
            static fn(): FeatureStatusChanged => new FeatureStatusChanged($featureId, FeatureStatus::OFF, 1)
        ));
        self::assertInstanceOf(Throwable::class, $this->fails(
            static fn(): FeaturePermissionChanged => new FeaturePermissionChanged($featureId, $permissionId, 1)
        ));
        self::assertInstanceOf(Throwable::class, $this->fails(
            static fn(): CreateFeature => CreateFeature::fromArray(['name' => 'dashboard', 'permission_id' => 7])
        ));
    }

    public function test_duplicate_missing_and_wrong_permission_view_are_explicit_failures(): void
    {
        $feature = $this->create('dashboard');
        self::assertInstanceOf(Throwable::class, $this->fails(fn(): Feature => $this->create('dashboard')));
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn(): FeatureView => new GetFeatureByIdHandler($this->features, $this->permissions)
                ->handle(QueryMessage::create(new GetFeatureById(FeatureId::generate())))
        ));
        self::assertInstanceOf(FeatureStateException::class, $this->fails(
            fn(): FeatureView => FeatureView::fromFeature($feature, $this->second)
        ));
        $view = FeatureView::fromFeature($feature, $this->first);
        self::assertSame($feature->getId(), $view->getFeatureId());
        self::assertSame($feature->getName(), $view->getName());
        self::assertSame($feature->getStatus(), $view->getStatus());
        self::assertSame($feature->getPermissionId(), $view->getPermissionId());
        self::assertSame(1, $view->toArray()['revision']);
        self::assertFalse($view->toArray()['binding_missing']);
    }

    private function create(string $name): Feature
    {
        new CreateFeatureHandler($this->features, $this->permissions, $this->uow, $this->events)
            ->handle(CommandMessage::create(new CreateFeature(FeatureName::fromString($name), $this->first->getId())));

        return $this->features->getByName(FeatureName::fromString($name));
    }

    private function changeStatus(Feature $feature, FeatureStatus $status, int $revision): void
    {
        new SetFeatureStatusHandler($this->features, $this->uow, $this->events)
            ->handle(CommandMessage::create(new SetFeatureStatus($feature->getId(), $status, $revision)));
    }

    private function binding(Feature $feature, PermissionId $id, int $revision): void
    {
        new SetFeaturePermissionHandler($this->features, $this->permissions, $this->uow, $this->events)
            ->handle(CommandMessage::create(new SetFeaturePermission($feature->getId(), $id, $revision)));
    }

    private function fails(callable $operation): Throwable
    {
        try {
            $operation();
            self::fail('Expected the operation to reject.');
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }
}
