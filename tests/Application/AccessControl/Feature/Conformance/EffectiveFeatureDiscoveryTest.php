<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture\ChildFeatureAction;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SelectedAction::class)]
#[CoversClass(FeatureFlag::class)]
#[CoversClass(FeatureReferences::class)]
#[CoversClass(FeatureDiscoveryResult::class)]
#[CoversClass(ProvisionFeaturesHandler::class)]
#[CoversClass(ValidateFeaturePreparationHandler::class)]
#[CoversClass(RemoveFeatureHandler::class)]
final class EffectiveFeatureDiscoveryTest extends TestCase
{
    public function test_effective_inventory_drives_preparation_provisioning_and_current_retirement(): void
    {
        $unit = new InMemoryUnitOfWork();
        $featureRepository = new InMemoryFeatureRepository($unit);
        $permissionRepository = new InMemoryPermissionRepository($unit);
        $eventDispatcher = new InMemoryEventDispatcher();
        $permissionRepository->add(Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('DEFAULT'),
            new DateTimeImmutable()
        ));
        $candidate = new FixtureFeatureDiscovery(
            FeatureReferenceScope::CANDIDATE,
            ChildFeatureAction::class,
            ['route', 'duplicate'],
            static fn(): bool => true
        );
        $names = array_map(
            static fn(FeatureName $name): string => $name->toString(),
            $candidate->discover(FeatureReferenceScope::CANDIDATE)
            ->getReferences(FeatureReferenceScope::CANDIDATE)->getNames()
        );
        self::assertSame([
            'parent-class', 'duplicate', 'parent-method', 'inherited-method', 'parent-invokable',
            'child-class', 'child-method', 'child-invokable', 'route'
        ], $names);
        $prepare = new ValidateFeaturePreparationHandler($candidate, $featureRepository, $permissionRepository);
        $result = $prepare->handle(QueryMessage::create(new ValidateFeaturePreparation()));
        self::assertCount(count($names), $result->getIssues());
        self::assertCount(0, $featureRepository->records);
        self::assertCount(0, $eventDispatcher->events());
        self::assertSame(0, $unit->transactions);

        $provision = new ProvisionFeaturesHandler(
            $candidate,
            $featureRepository,
            $permissionRepository,
            $unit,
            $eventDispatcher
        );
        $provision->handle(CommandMessage::create(new ProvisionFeatures('DEFAULT')));
        self::assertCount(count($names), $featureRepository->records);
        self::assertCount(count($names), $eventDispatcher->events());
        foreach ($featureRepository->records as $feature) {
            self::assertSame(FeatureStatus::OFF, $feature->getStatus());
            self::assertSame(1, $feature->getRevision());
        }

        self::assertTrue($prepare->handle(QueryMessage::create(new ValidateFeaturePreparation()))->isPrepared());
        $before = $featureRepository->records;
        $provision->handle(CommandMessage::create(new ProvisionFeatures(null)));
        self::assertSame($before, $featureRepository->records);
        self::assertCount(count($names), $eventDispatcher->events());

        $current = new FixtureFeatureDiscovery(
            FeatureReferenceScope::CURRENT,
            ChildFeatureAction::class,
            ['route'],
            static fn(): bool => true
        );
        foreach ($before as $feature) {
            try {
                new RemoveFeatureHandler($current, $featureRepository, $unit, $eventDispatcher)->handle(
                    CommandMessage::create(new RemoveFeature($feature->getId(), 1))
                );
                self::fail('Every effective current reference must block retirement.');
            } catch (RuleViolation) {
                self::assertSame($feature, $featureRepository->getById($feature->getId()));
            }
        }

        $empty = new FixtureFeatureDiscovery(
            FeatureReferenceScope::CURRENT,
            new stdClass(),
            [],
            static fn(): bool => true
        );
        foreach ($before as $feature) {
            new RemoveFeatureHandler($empty, $featureRepository, $unit, $eventDispatcher)->handle(
                CommandMessage::create(new RemoveFeature($feature->getId(), 1))
            );
        }

        self::assertSame([], $featureRepository->records);
    }

    public function test_selected_candidate_inventory_cannot_be_relabeled_current(): void
    {
        $discovery = new FixtureFeatureDiscovery(
            FeatureReferenceScope::CANDIDATE,
            ChildFeatureAction::class,
            [],
            static fn(): bool => true
        );
        $this->expectException(FeatureDiscoveryException::class);
        $discovery->discover(FeatureReferenceScope::CURRENT)->getReferences(FeatureReferenceScope::CURRENT);
    }
}
