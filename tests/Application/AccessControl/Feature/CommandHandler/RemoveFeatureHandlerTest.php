<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureRemoved;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureProvisioningException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Service\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RemoveFeatureHandler::class)]
#[CoversClass(RemoveFeature::class)]
#[CoversClass(FeatureRemoved::class)]
final class RemoveFeatureHandlerTest extends TestCase
{
    private InMemoryUnitOfWork $unit;

    private InMemoryFeatureRepository $features;

    private InMemoryPermissionRepository $permissions;

    private InMemoryEventDispatcher $events;

    private Permission $permission;

    private Feature $feature;

    protected function setUp(): void
    {
        $this->unit = new InMemoryUnitOfWork();
        $this->features = new InMemoryFeatureRepository($this->unit);
        $this->permissions = new InMemoryPermissionRepository($this->unit);
        $this->events = new InMemoryEventDispatcher();
        $this->permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('FIRST'),
            new DateTimeImmutable()
        );
        $this->permissions->add($this->permission);
        $this->feature = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('dashboard'),
            $this->permission->getId()
        );
        $this->features->seed($this->feature);
    }

    public function test_message_fact_and_validation(): void
    {
        self::assertSame(RemoveFeature::class, RemoveFeatureHandler::commandRegistration());
        $command = new RemoveFeature($this->feature->getId(), 1);
        $event = new FeatureRemoved($this->feature->getId(), $this->feature->getName());
        self::assertSame([
            'feature_id'        => $this->feature->getId()->toString(),
            'expected_revision' => 1
        ], $command->toArray());
        self::assertSame($command->toArray(), RemoveFeature::fromArray($command->toArray())->toArray());
        self::assertSame($event->toArray(), FeatureRemoved::fromArray($event->toArray())->toArray());
        self::assertSame($this->feature->getId(), $command->getFeatureId());
        self::assertSame(1, $command->getExpectedRevision());
        self::assertSame($this->feature->getId(), $event->getFeatureId());
        self::assertSame($this->feature->getName(), $event->getName());
        foreach ([[], ['feature_id' => 1, 'expected_revision' => 1]] as $invalid) {
            self::assertInstanceOf(DomainException::class, $this->fails(
                static fn(): RemoveFeature => RemoveFeature::fromArray($invalid)
            ));
        }

        foreach ([[], ['feature_id' => $this->feature->getId()->toString(), 'name' => 7]] as $invalid) {
            self::assertInstanceOf(DomainException::class, $this->fails(
                static fn(): FeatureRemoved => FeatureRemoved::fromArray($invalid)
            ));
        }

        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            fn(): RemoveFeature => new RemoveFeature($this->feature->getId(), 0)
        ));
    }

    public function test_both_reference_sources_at_every_status_fail_closed_until_both_removed(): void
    {
        $attribute = new class {
            #[FeatureFlag('dashboard')]
            public function show(): void
            {
            }
        };
        foreach (FeatureStatus::cases() as $status) {
            $this->features->seed(Feature::reconstitute(
                $this->feature->getId(),
                $this->feature->getName(),
                $this->permission->getId(),
                $status,
                3
            ));
            foreach (
                [[$attribute, []], [new class {
                }, ['dashboard']], [$attribute, ['dashboard']]] as [$code, $registrations]
            ) {
                $before = count($this->events->events());
                self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
                    fn() => $this->remove($this->discovery($code, $registrations), 3)
                ));
                self::assertCount($before + 1, $this->events->events());
                self::assertInstanceOf(CommandFailedEvent::class, $this->events->events()[$before]);
                self::assertSame($status, $this->features->getById($this->feature->getId())?->getStatus());
            }
        }

        $this->remove($this->discovery(new class {
        }, ['unrelated']), 3);
        self::assertNull($this->features->getById($this->feature->getId()));
        self::assertInstanceOf(FeatureRemoved::class, $this->events->events()[count($this->events->events()) - 1]);
        self::assertTrue($this->permissions->remove($this->permission));
    }

    public function test_candidate_scope_incomplete_scanner_exception_and_unknown_id_never_delete(): void
    {
        $candidate = $this->discovery(new class {
        }, []);
        $wrongScope = new readonly class ($candidate) implements FeatureReferenceDiscovery {
            public function __construct(private FeatureReferenceDiscovery $candidate)
            {
            }

            public function discover(FeatureReferenceScope $scope): FeatureDiscoveryResult
            {
                return $this->candidate->discover(FeatureReferenceScope::CANDIDATE);
            }
        };
        $unavailable = $this->discovery(new class {
        }, [], static fn(): bool => false);
        $explodes = new readonly class implements FeatureReferenceDiscovery {
            public function discover(FeatureReferenceScope $scope): FeatureDiscoveryResult
            {
                throw new RuntimeException('Scanner unavailable.');
            }
        };
        foreach (
            [[$wrongScope, FeatureDiscoveryException::class], [$unavailable, FeatureDiscoveryException::class],
                [$explodes, RuntimeException::class]] as [$discovery, $failureClass]
        ) {
            self::assertInstanceOf($failureClass, $this->fails(fn() => $this->remove($discovery, 1)));
            self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        }

        self::assertInstanceOf(FeatureDiscoveryException::class, $this->fails(
            fn() => $this->remove($wrongScope, 1)
        ));
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn() => $this->remove($candidate, 1, FeatureId::generate())
        ));
        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            fn() => $this->remove($candidate, 2)
        ));
        self::assertSame(0, $this->features->writes);
    }

    public function test_final_state_compare_rejects_concurrent_edit_and_old_identity(): void
    {
        $discovery = $this->discovery(new class {
        }, []);
        $newer = $this->feature->withStatus(FeatureStatus::ON);
        $this->features->beforeRemove = function () use ($newer): void {
            $this->features->seed($newer);
        };
        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            fn() => $this->remove($discovery, 1)
        ));
        self::assertSame($newer, $this->features->getById($this->feature->getId()));
        $this->features->beforeRemove = null;
        $this->remove($discovery, 2);
        $replacement = Feature::define(FeatureId::generate(), $this->feature->getName(), $this->permission->getId());
        $this->features->seed($replacement);
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn() => $this->remove($discovery, 1)
        ));
        self::assertSame($replacement, $this->features->getByName($replacement->getName()));
    }

    public function test_success_publishes_exact_removal_only_after_commit_without_permission_changes(): void
    {
        $fact = null;
        $this->events = new InMemoryEventDispatcher(function (object $event) use (&$fact): void {
            self::assertInstanceOf(FeatureRemoved::class, $event);
            self::assertFalse($this->unit->transactionActive);
            self::assertTrue($this->unit->transactionCompleted);
            self::assertNull($this->features->getById($this->feature->getId()));
            self::assertFalse($this->permissions->hasFeatureReference($this->permission->getId()));
            self::assertSame($this->permission, $this->permissions->getById($this->permission->getId()));
            $fact = $event;
        });
        $this->remove($this->discovery(new class {
        }, []), 1);
        self::assertInstanceOf(FeatureRemoved::class, $fact);
        self::assertSame([
            'feature_id' => $this->feature->getId()->toString(),
            'name'       => 'dashboard'
        ], $fact->toArray());
        self::assertCount(1, $this->events->events());
    }

    public function test_malformed_declaration_after_partial_scan_does_not_establish_absence(): void
    {
        $code = new class {
            #[FeatureFlag('other-valid')]
            public function first(): void
            {
            }

            #[FeatureFlag('Invalid')]
            public function second(): void
            {
            }
        };
        self::assertInstanceOf(FeatureDiscoveryException::class, $this->fails(
            fn() => $this->remove($this->discovery($code, []), 1)
        ));
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertSame(0, $this->features->writes);
        self::assertCount(1, $this->events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $this->events->events()[0]);
    }

    public function test_edit_during_discovery_cannot_be_removed_after_successful_absence_check(): void
    {
        $otherId = PermissionId::generate();
        $newer = $this->feature->withPermission($otherId);
        $discovery = $this->discovery(new class {
        }, [], function () use ($newer): bool {
            $this->features->seed($newer);

            return true;
        });
        self::assertInstanceOf(FeatureRevisionException::class, $this->fails(
            fn() => $this->remove($discovery, 1)
        ));
        self::assertSame($newer, $this->features->getById($this->feature->getId()));
        self::assertSame(0, $this->features->writes);
        self::assertCount(1, $this->events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $this->events->events()[0]);
    }

    public function test_write_failure_rolls_back_deletion_and_original_throwable_survives_failure_publisher(): void
    {
        $failure = new RuntimeException('Controlled write fault.');
        $this->features->afterRemove = function () use ($failure): void {
            self::assertTrue($this->unit->authorizationReferenceState()->isReferenceFenceHeld());
            throw $failure;
        };
        $this->events = new InMemoryEventDispatcher(static function (): void {
            throw new RuntimeException('Failure publisher fault.');
        });
        self::assertSame($failure, $this->fails(fn() => $this->remove($this->discovery(new class {
        }, []), 1)));
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertSame($this->permission, $this->permissions->getById($this->permission->getId()));
        self::assertTrue($this->permissions->hasFeatureReference($this->permission->getId()));
    }

    public function test_current_cleanup_and_new_candidate_reprovision_off_without_restoring_old_audience(): void
    {
        $old = $this->feature;
        $other = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('OTHER'),
            new DateTimeImmutable()
        );
        $this->permissions->add($other);
        new SetFeatureStatusHandler($this->features, $this->unit, $this->events)->handle(
            CommandMessage::create(new SetFeatureStatus($old->getId(), FeatureStatus::ON, 1))
        );
        $code = new class {
            #[FeatureFlag('dashboard')]
            public function show(): void
            {
            }
        };
        self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
            fn() => $this->remove($this->discovery($code, ['dashboard']), 2)
        ));
        self::assertInstanceOf(FeatureReferenceException::class, $this->fails(
            fn() => $this->remove($this->discovery(new class {
            }, ['dashboard']), 2)
        ));
        $this->remove($this->discovery(new class {
        }, []), 2);
        $availability = new FeatureAvailability($this->features, $this->permissions);
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn(): bool => $availability->isAvailable($old->getName(), null)
        ));
        $candidate = $this->discovery($code, ['dashboard']);
        $provision = new ProvisionFeaturesHandler(
            $candidate,
            $this->features,
            $this->permissions,
            $this->unit,
            $this->events
        );
        self::assertInstanceOf(FeatureProvisioningException::class, $this->fails(
            fn() => $provision->handle(CommandMessage::create(new ProvisionFeatures(null)))
        ));
        self::assertNull($this->features->getByName($old->getName()));
        $provision->handle(CommandMessage::create(new ProvisionFeatures('OTHER')));
        $new = $this->features->getByName($old->getName());
        self::assertInstanceOf(Feature::class, $new);
        self::assertFalse($old->getId()->equals($new->getId()));
        self::assertSame(FeatureStatus::OFF, $new->getStatus());
        self::assertSame(1, $new->getRevision());
        self::assertSame($other->getId(), $new->getPermissionId());
        self::assertTrue(new ValidateFeaturePreparationHandler($candidate, $this->features, $this->permissions)
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()))->isPrepared());
        self::assertFalse($availability->isAvailable($new->getName(), null));
        $oldAudience = new AuthenticatedAgentPrincipal(AgentId::generate(), AgentCredentialId::generate(), 1, 1, [
            new PrincipalPermission($this->permission->getId(), $this->permission->getName())
        ]);
        self::assertFalse($availability->isAvailable($new->getName(), $oldAudience));
        self::assertSame($this->permission, $this->permissions->getById($this->permission->getId()));
        $provision->handle(CommandMessage::create(new ProvisionFeatures('FIRST')));
        self::assertSame($new->getId()->toString(), $this->features->getByName($new->getName())?->getId()->toString());
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn() => $this->remove($this->discovery(new class {
            }, []), 2)
        ));
        self::assertInstanceOf(FeatureNotFoundException::class, $this->fails(
            fn() => new SetFeatureStatusHandler($this->features, $this->unit, $this->events)->handle(
                CommandMessage::create(new SetFeatureStatus($old->getId(), FeatureStatus::ON, 2))
            )
        ));
        $retained = $this->features->getById($new->getId());
        self::assertInstanceOf(Feature::class, $retained);
        self::assertSame($new->getId()->toString(), $retained->getId()->toString());
        self::assertSame(FeatureStatus::OFF, $retained->getStatus());
        self::assertSame(1, $retained->getRevision());
        self::assertTrue($other->getId()->equals($retained->getPermissionId()));
    }

    public function test_commit_failure_and_publication_failure_preserve_correct_state_and_order(): void
    {
        $discovery = $this->discovery(new class {
        }, []);
        $this->unit->failNextCommit = true;
        self::assertInstanceOf(RuntimeException::class, $this->fails(fn() => $this->remove($discovery, 1)));
        self::assertSame($this->feature, $this->features->getById($this->feature->getId()));
        self::assertFalse($this->permissions->remove($this->permission));
        self::assertInstanceOf(CommandFailedEvent::class, $this->events->events()[0]);
        $this->events = new InMemoryEventDispatcher(function (object $event): void {
            if ($event instanceof FeatureRemoved) {
                self::assertTrue($this->unit->transactionCompleted);
                self::assertNull($this->features->getById($this->feature->getId()));
                throw new RuntimeException('Publication failed.');
            }
        });
        self::assertInstanceOf(RuntimeException::class, $this->fails(fn() => $this->remove($discovery, 1)));
        self::assertNull($this->features->getById($this->feature->getId()));
        self::assertTrue($this->permissions->remove($this->permission));
        self::assertInstanceOf(CommandFailedEvent::class, $this->events->events()[0]);
    }

    public function test_both_publishers_failing_do_not_disguise_or_restore_committed_deletion(): void
    {
        $publicationFailure = new RuntimeException('Removal publication failed.');
        $failedCommand = null;
        $this->events = new InMemoryEventDispatcher(
            function (object $event) use ($publicationFailure, &$failedCommand): void {
                if ($event instanceof FeatureRemoved) {
                    self::assertFalse($this->unit->transactionActive);
                    self::assertNull($this->features->getById($this->feature->getId()));
                    throw $publicationFailure;
                }

                self::assertInstanceOf(CommandFailedEvent::class, $event);
                $failedCommand = $event;
                throw new RuntimeException('Failure publication also failed.');
            }
        );
        self::assertSame($publicationFailure, $this->fails(fn() => $this->remove($this->discovery(new class {
        }, []), 1)));
        self::assertInstanceOf(CommandFailedEvent::class, $failedCommand);
        self::assertNull($this->features->getById($this->feature->getId()));
        self::assertFalse($this->permissions->hasFeatureReference($this->permission->getId()));
        self::assertSame($this->permission, $this->permissions->getById($this->permission->getId()));
    }

    /** @param list<string> $registrations */
    private function discovery(object $code, array $registrations, ?Closure $finish = null): FixtureFeatureDiscovery
    {
        return new FixtureFeatureDiscovery($code, $registrations, $finish ?? static fn(): bool => true);
    }

    private function remove(FeatureReferenceDiscovery $discovery, int $revision, ?FeatureId $id = null): void
    {
        new RemoveFeatureHandler($discovery, $this->features, $this->unit, $this->events)
            ->handle(CommandMessage::create(new RemoveFeature($id ?? $this->feature->getId(), $revision)));
    }

    private function fails(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected rejection.');
    }
}
