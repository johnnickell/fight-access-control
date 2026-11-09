<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureProvisioningException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Exception\PermissionNameException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ProvisionFeaturesHandler::class)]
final class ProvisionFeaturesHandlerTest extends TestCase
{
    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryFeatureRepository $featureRepository;

    private InMemoryPermissionRepository $permissionRepository;

    private InMemoryEventDispatcher $eventDispatcher;

    private Permission $default;

    private Permission $protected;

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->featureRepository = new InMemoryFeatureRepository($this->unitOfWork);
        $this->permissionRepository = new InMemoryPermissionRepository($this->unitOfWork);
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->default = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('TEST_FEATURES'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $this->protected = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('PROTECTED_PREVIEW'),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $this->permissionRepository->add($this->default);
        $this->permissionRepository->add($this->protected);
    }

    public function test_atomic_pass_creates_only_missing_names_and_publishes_after_every_insert_commits(): void
    {
        $names = ['new-checkout', 'dashboard', 'new-checkout'];
        $existing = [];
        foreach (FeatureStatus::cases() as $status) {
            $feature = $this->stored('existing-'.$status->value, $status);
            $this->featureRepository->seed($feature);
            $existing[] = $feature;
            $names[] = $feature->getName()->toString();
        }

        $unregistered = $this->stored('unregistered', FeatureStatus::ON);
        $this->featureRepository->seed($unregistered);
        $this->featureRepository->afterAdd = function (): void {
            self::assertTrue($this->unitOfWork->transactionActive);
            self::assertTrue($this->unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
            self::assertSame([], $this->eventDispatcher->events());
        };
        $this->eventDispatcher = new InMemoryEventDispatcher(function (Event $event): void {
            self::assertInstanceOf(FeatureCreated::class, $event);
            self::assertTrue($this->unitOfWork->transactionCompleted);
            self::assertFalse($this->unitOfWork->transactionActive);
            self::assertFalse($this->unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
            self::assertCount(6, $this->featureRepository->records);
        });

        $this->handler($names)->handle($this->message());

        self::assertSame(ProvisionFeatures::class, ProvisionFeaturesHandler::commandRegistration());
        self::assertSame(1, $this->unitOfWork->transactions);
        self::assertSame(2, $this->featureRepository->writes);
        self::assertCount(2, $this->eventDispatcher->events());
        foreach ($this->eventDispatcher->events() as $event) {
            self::assertInstanceOf(FeatureCreated::class, $event);
            $created = $this->featureRepository->getById($event->getFeatureId());
            self::assertInstanceOf(Feature::class, $created);
            self::assertContains($created->getName()->toString(), ['new-checkout', 'dashboard']);
            self::assertSame($created->getName(), $event->getName());
            self::assertSame($this->default->getId(), $created->getPermissionId());
            self::assertSame($created->getPermissionId(), $event->getPermissionId());
            self::assertSame(FeatureStatus::OFF, $created->getStatus());
            self::assertSame(1, $created->getRevision());
        }

        foreach ([...$existing, $unregistered] as $feature) {
            self::assertSame($feature, $this->featureRepository->getById($feature->getId()));
        }

        self::assertSame($this->default, $this->permissionRepository->getById($this->default->getId()));
        self::assertSame($this->protected, $this->permissionRepository->getById($this->protected->getId()));
    }

    public function test_protected_default_is_a_reference_not_a_grant_or_tier_change(): void
    {
        $this->handler()->handle($this->message('PROTECTED_PREVIEW'));

        self::assertCount(2, $this->featureRepository->records);
        foreach ($this->featureRepository->records as $feature) {
            self::assertSame($this->protected->getId(), $feature->getPermissionId());
            self::assertSame(FeatureStatus::OFF, $feature->getStatus());
        }

        self::assertSame($this->protected, $this->permissionRepository->getById($this->protected->getId()));
        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $this->protected->getTier());
    }

    #[DataProvider('invalidDefaults')]
    public function test_required_invalid_default_rejects_without_writes_or_creation_facts(
        ?string $name,
        string $exception
    ): void {
        $command = new ProvisionFeatures($name);
        $failure = $this->failure(fn() => $this->handler()->handle(CommandMessage::create($command)));

        self::assertInstanceOf($exception, $failure);
        self::assertSame([], $this->featureRepository->records);
        self::assertSame(0, $this->featureRepository->writes);
        self::assertFalse($this->unitOfWork->transactionCompleted);
        $this->assertFailureEvent($command, $failure);
    }

    /** @return iterable<string, array{?string, class-string<Throwable>}> */
    public static function invalidDefaults(): iterable
    {
        yield 'absent' => [null, FeatureProvisioningException::class];
        yield 'empty' => ['', PermissionNameException::class];
        yield 'malformed' => ['test_features', PermissionNameException::class];
        yield 'unresolved' => ['UNDEFINED_PERMISSION', FeatureProvisioningException::class];
    }

    public function test_noop_preserves_even_broken_bindings_without_validating_unused_configuration(): void
    {
        $stored = $this->stored('existing', FeatureStatus::PREVIEW, PermissionId::generate());
        $this->featureRepository->seed($stored);
        $permissionRepository = $this->createMock(PermissionRepository::class);
        $permissionRepository->expects(self::never())->method('getByName');
        $permissionRepository->expects(self::never())->method('getById');
        $permissionRepository->expects(self::never())->method('add');

        foreach ([null, '', 'invalid', 'UNDEFINED_PERMISSION'] as $configuration) {
            $this->handler(['existing'], permissionRepository: $permissionRepository)->handle(
                $this->message($configuration)
            );
        }

        self::assertSame([$stored->getId()->toString() => $stored], $this->featureRepository->records);
        self::assertSame(0, $this->featureRepository->writes);
        self::assertSame([], $this->eventDispatcher->events());
    }

    public function test_complete_empty_inventory_succeeds_without_configuration_or_writes(): void
    {
        $this->handler([])->handle($this->message(null));

        self::assertSame([], $this->featureRepository->records);
        self::assertSame([], $this->featureRepository->lookups);
        self::assertSame(0, $this->featureRepository->writes);
        self::assertSame([], $this->eventDispatcher->events());
    }

    public function test_incomplete_and_wrong_scope_discovery_cannot_start_a_transaction(): void
    {
        $results = [
            FeatureDiscoveryResult::unavailable(FeatureReferenceScope::CANDIDATE),
            FeatureDiscoveryResult::complete(FeatureReferenceScope::CURRENT, new FeatureReferences())
        ];
        foreach ($results as $result) {
            $this->eventDispatcher = new InMemoryEventDispatcher();
            $discovery = $this->createMock(FeatureReferenceDiscovery::class);
            $discovery->expects(self::once())->method('discover')->with(FeatureReferenceScope::CANDIDATE)
                ->willReturn($result);
            $command = new ProvisionFeatures('TEST_FEATURES');

            $failure = $this->failure(fn() => $this->handler(discovery: $discovery)
                ->handle(CommandMessage::create($command)));

            self::assertInstanceOf(FeatureDiscoveryException::class, $failure);
            self::assertSame(0, $this->unitOfWork->transactions);
            self::assertSame([], $this->featureRepository->records);
            $this->assertFailureEvent($command, $failure);
        }
    }

    public function test_malformed_native_declaration_after_valid_discovery_does_not_provision_partial_inventory(): void
    {
        $code = new class {
            #[FeatureFlag('valid')]
            public function valid(): void
            {
            }

            #[FeatureFlag('Bad_Name')]
            public function invalid(): void
            {
            }
        };
        $discovery = new FixtureFeatureDiscovery(
            FeatureReferenceScope::CANDIDATE,
            $code,
            [],
            static fn(): bool => true
        );
        $failure = $this->failure(fn() => $this->handler(discovery: $discovery)->handle($this->message()));

        self::assertInstanceOf(FeatureDiscoveryException::class, $failure);
        self::assertSame([], $this->featureRepository->records);
        self::assertSame(0, $this->unitOfWork->transactions);
    }

    public function test_discovery_lookup_and_permission_storage_failures_rethrow_the_same_failure(): void
    {
        foreach (['discovery', 'feature-read', 'permission-read'] as $boundary) {
            $this->eventDispatcher = new InMemoryEventDispatcher();
            $failure = new RuntimeException('Storage unavailable: '.$boundary);
            $discovery = null;
            $featureRepository = null;
            $permissionRepository = null;
            if ($boundary === 'discovery') {
                $discovery = $this->createStub(FeatureReferenceDiscovery::class);
                $discovery->method('discover')->willThrowException($failure);
            } elseif ($boundary === 'feature-read') {
                $featureRepository = $this->createMock(FeatureRepository::class);
                $featureRepository->method('getByName')->willThrowException($failure);
                $featureRepository->expects(self::never())->method('add');
            } else {
                $permissionRepository = $this->createStub(PermissionRepository::class);
                $permissionRepository->method('getByName')->willThrowException($failure);
            }

            $command = new ProvisionFeatures('TEST_FEATURES');
            $handler = $this->handler(
                discovery: $discovery,
                featureRepository: $featureRepository,
                permissionRepository: $permissionRepository
            );

            self::assertSame($failure, $this->failure(fn() => $handler->handle(CommandMessage::create($command))));
            self::assertSame([], $this->featureRepository->records);
            $this->assertFailureEvent($command, $failure);
        }
    }

    public function test_later_insertion_failure_rolls_back_earlier_inserts_and_retry_rereads(): void
    {
        $failure = new RuntimeException('Second insert failed.');
        $this->featureRepository->beforeAdd = static function (Feature $feature) use ($failure): void {
            if ($feature->getName()->toString() === 'dashboard') {
                throw $failure;
            }
        };
        $command = new ProvisionFeatures('TEST_FEATURES');
        self::assertSame($failure, $this->failure(fn() => $this->handler()->handle(CommandMessage::create($command))));
        self::assertSame(1, $this->featureRepository->writes);
        self::assertSame([], $this->featureRepository->records);
        $this->assertFailureEvent($command, $failure);
        self::assertFalse($this->unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());

        $this->featureRepository->beforeAdd = null;
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->handler()->handle($this->message('PROTECTED_PREVIEW'));

        self::assertCount(2, $this->featureRepository->records);
        self::assertCount(2, $this->eventDispatcher->events());
        self::assertSame(['new-checkout', 'dashboard', 'new-checkout', 'dashboard'], $this->featureRepository->lookups);
        foreach ($this->featureRepository->records as $feature) {
            self::assertSame($this->protected->getId(), $feature->getPermissionId());
        }
    }

    public function test_permission_removal_cannot_win_after_first_insert_of_atomic_provisioning_pass(): void
    {
        $this->featureRepository->beforeAdd = function (Feature $feature): void {
            if ($feature->getName()->toString() !== 'dashboard') {
                return;
            }

            self::assertTrue($this->unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
            self::assertFalse($this->permissionRepository->remove($this->default));
            self::assertSame($this->default, $this->permissionRepository->getById($this->default->getId()));
        };

        $this->handler()->handle($this->message());

        self::assertCount(2, $this->featureRepository->records);
        self::assertSame($this->default, $this->permissionRepository->getById($this->default->getId()));
        self::assertCount(2, $this->eventDispatcher->events());
    }

    public function test_reference_loss_after_default_resolution_rejects_the_entire_pass(): void
    {
        $this->featureRepository->beforeAdd = function (Feature $feature): void {
            if ($feature->getName()->toString() === 'dashboard') {
                // Models authoritative reference rejection, not an allowed concurrent deletion under a held fence.
                $this->unitOfWork->authorizationReferenceState()->removePermission($feature->getPermissionId());
            }
        };
        $failure = $this->failure(fn() => $this->handler()->handle($this->message()));

        self::assertInstanceOf(StateConflict::class, $failure);
        self::assertSame(1, $this->featureRepository->writes);
        self::assertSame([], $this->featureRepository->records);
        self::assertCount(1, $this->eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $this->eventDispatcher->events()[0]);
    }

    public function test_concurrent_name_winner_survives_losing_pass_rollback_and_fresh_retry(): void
    {
        $winner = $this->stored('dashboard', FeatureStatus::ON, $this->protected->getId());
        $this->featureRepository->beforeAdd = function (Feature $feature) use ($winner): void {
            if ($feature->getName()->toString() === 'dashboard') {
                $this->featureRepository->seed($winner);
            }
        };

        $failure = $this->failure(fn() => $this->handler()->handle($this->message()));

        self::assertInstanceOf(StateConflict::class, $failure);
        self::assertSame([$winner->getId()->toString() => $winner], $this->featureRepository->records);
        self::assertSame(1, $this->featureRepository->writes);
        self::assertCount(1, $this->eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $this->eventDispatcher->events()[0]);

        $this->featureRepository->beforeAdd = null;
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->handler()->handle($this->message());

        self::assertSame($winner, $this->featureRepository->getByName(FeatureName::fromString('dashboard')));
        self::assertCount(2, $this->featureRepository->records);
        self::assertCount(1, $this->eventDispatcher->events());
        self::assertInstanceOf(FeatureCreated::class, $this->eventDispatcher->events()[0]);
        self::assertSame('new-checkout', $this->eventDispatcher->events()[0]->getName()->toString());
    }

    public function test_identity_collision_never_overwrites_a_different_name(): void
    {
        $winner = null;
        $this->featureRepository->beforeAdd = function (Feature $feature) use (&$winner): void {
            $winner = Feature::reconstitute(
                $feature->getId(),
                FeatureName::fromString('other-name'),
                $this->protected->getId(),
                FeatureStatus::PREVIEW,
                8
            );
            $this->featureRepository->seed($winner);
        };

        $failure = $this->failure(fn() => $this->handler()->handle($this->message()));

        self::assertInstanceOf(StateConflict::class, $failure);
        self::assertInstanceOf(Feature::class, $winner);
        self::assertSame([$winner->getId()->toString() => $winner], $this->featureRepository->records);
        self::assertSame(0, $this->featureRepository->writes);
    }

    public function test_commit_failure_rolls_back_all_inserts_without_creation_facts(): void
    {
        $this->unitOfWork->failNextCommit = true;
        $command = new ProvisionFeatures('TEST_FEATURES');
        $failure = $this->failure(fn() => $this->handler()->handle(CommandMessage::create($command)));

        self::assertSame([], $this->featureRepository->records);
        self::assertSame(2, $this->featureRepository->writes);
        self::assertFalse($this->unitOfWork->transactionCompleted);
        $this->assertFailureEvent($command, $failure);
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_lost_commit_outcome_requires_storage_based_retry(bool $committed): void
    {
        $failure = new RuntimeException('Commit outcome unavailable.');
        $uncertain = $this->createStub(TransactionalUnitOfWork::class);
        $uncertain->method('commitTransactional')->willReturnCallback(function (callable $operation) use (
            $committed,
            $failure
        ): never {
            $this->unitOfWork->commitTransactional(static function () use ($operation, $committed, $failure): void {
                $operation();
                if (!$committed) {
                    throw $failure;
                }
            });
            throw $failure;
        });
        $command = new ProvisionFeatures('TEST_FEATURES');

        self::assertSame($failure, $this->failure(fn() => $this->handler(unitOfWork: $uncertain)
            ->handle(CommandMessage::create($command))));
        self::assertCount($committed ? 2 : 0, $this->featureRepository->records);
        $beforeRetry = $this->featureRepository->records;
        $this->assertFailureEvent($command, $failure);

        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->handler()->handle($this->message('PROTECTED_PREVIEW'));

        self::assertCount(2, $this->featureRepository->records);
        self::assertCount($committed ? 0 : 2, $this->eventDispatcher->events());
        if ($committed) {
            self::assertSame($beforeRetry, $this->featureRepository->records);
        } else {
            foreach ($this->featureRepository->records as $feature) {
                self::assertSame($this->protected->getId(), $feature->getPermissionId());
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function uncertainOutcomes(): iterable
    {
        yield 'committed but response lost' => [true];
        yield 'not committed' => [false];
    }

    #[DataProvider('publicationFailures')]
    public function test_publication_failure_preserves_committed_choices_and_retry_does_not_replay(
        int $failAt,
        bool $failurePublisherFails
    ): void {
        $failure = new RuntimeException('Creation publisher failed.');
        $attempts = 0;
        $failureEvents = [];
        $this->eventDispatcher = new InMemoryEventDispatcher(function (Event $event) use (
            &$attempts,
            &$failureEvents,
            $failAt,
            $failurePublisherFails,
            $failure
        ): void {
            self::assertFalse($this->unitOfWork->transactionActive);
            self::assertCount(2, $this->featureRepository->records);
            if ($event instanceof CommandFailedEvent) {
                $failureEvents[] = $event;
                if ($failurePublisherFails) {
                    throw new RuntimeException('Failure publisher also failed.');
                }
            } elseif (++$attempts === $failAt) {
                throw $failure;
            }
        });
        $command = new ProvisionFeatures('TEST_FEATURES');

        self::assertSame($failure, $this->failure(fn() => $this->handler()->handle(CommandMessage::create($command))));
        self::assertCount(1, $failureEvents);
        self::assertSame($command, $failureEvents[0]->getCommand());
        self::assertSame($failAt, $attempts);
        self::assertCount(2, $this->featureRepository->records);
        $committed = $this->featureRepository->records;

        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->handler()->handle($this->message('PROTECTED_PREVIEW'));

        self::assertSame($committed, $this->featureRepository->records);
        self::assertSame(2, $this->featureRepository->writes);
        self::assertSame([], $this->eventDispatcher->events());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function publicationFailures(): iterable
    {
        yield 'first creation fact' => [1, false];
        yield 'later creation fact' => [2, false];
        yield 'both publishers' => [1, true];
    }

    public function test_successful_retry_preserves_choices_but_a_seeded_absent_name_gets_new_identity(): void
    {
        $this->handler()->handle($this->message());
        $original = $this->featureRepository->records;
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $this->handler()->handle($this->message('PROTECTED_PREVIEW'));
        self::assertSame($original, $this->featureRepository->records);
        self::assertSame([], $this->eventDispatcher->events());

        $removed = $this->featureRepository->getByName(FeatureName::fromString('dashboard'));
        self::assertInstanceOf(Feature::class, $removed);
        // Seeded absence only: actual guarded deletion is TASK-00067's composed acceptance.
        unset($this->featureRepository->records[$removed->getId()->toString()]);
        $this->handler()->handle($this->message('PROTECTED_PREVIEW'));

        $new = $this->featureRepository->getByName(FeatureName::fromString('dashboard'));
        self::assertInstanceOf(Feature::class, $new);
        self::assertFalse($new->getId()->equals($removed->getId()));
        self::assertSame(FeatureStatus::OFF, $new->getStatus());
        self::assertSame(1, $new->getRevision());
        self::assertSame($this->protected->getId(), $new->getPermissionId());
        self::assertCount(1, $this->eventDispatcher->events());
        self::assertNull($this->featureRepository->getById($removed->getId()));
    }

    /** @param list<string> $names */
    private function handler(
        array $names = ['new-checkout', 'dashboard'],
        ?FeatureReferenceDiscovery $discovery = null,
        ?FeatureRepository $featureRepository = null,
        ?PermissionRepository $permissionRepository = null,
        ?TransactionalUnitOfWork $unitOfWork = null
    ): ProvisionFeaturesHandler {
        $discovery ??= new FixtureFeatureDiscovery(FeatureReferenceScope::CANDIDATE, new class {
        }, $names, static fn(): bool => true);

        return new ProvisionFeaturesHandler(
            $discovery,
            $featureRepository ?? $this->featureRepository,
            $permissionRepository ?? $this->permissionRepository,
            $unitOfWork ?? $this->unitOfWork,
            $this->eventDispatcher
        );
    }

    private function message(?string $default = 'TEST_FEATURES'): CommandMessage
    {
        return CommandMessage::create(new ProvisionFeatures($default));
    }

    private function stored(string $name, FeatureStatus $status, ?PermissionId $permissionId = null): Feature
    {
        return Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString($name),
            $permissionId ?? $this->protected->getId(),
            $status,
            5
        );
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

    private function assertFailureEvent(ProvisionFeatures $command, Throwable $failure): void
    {
        self::assertCount(1, $this->eventDispatcher->events());
        $event = $this->eventDispatcher->events()[0];
        self::assertInstanceOf(CommandFailedEvent::class, $event);
        self::assertSame($command, $event->getCommand());
        self::assertSame('Feature provisioning failed.', $event->getErrorMessage());
        self::assertNotSame($failure->getMessage(), $event->getErrorMessage());
    }
}
