<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationProblem;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Service\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ValidateFeaturePreparationHandler::class)]
#[CoversClass(ValidateFeaturePreparation::class)]
#[CoversClass(FeaturePreparationIssue::class)]
#[CoversClass(FeaturePreparationResult::class)]
final class ValidateFeaturePreparationHandlerTest extends TestCase
{
    public function test_query_round_trips_and_complete_empty_discovery_needs_no_catalog_or_transaction(): void
    {
        $query = new ValidateFeaturePreparation();
        self::assertEquals($query, ValidateFeaturePreparation::fromArray($query->toArray()));
        self::assertSame([], $query->toArray());
        self::assertSame(ValidateFeaturePreparation::class, ValidateFeaturePreparationHandler::queryRegistration());

        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::never())->method('getByName');
        $features->expects(self::never())->method('add');
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::never())->method('getById');
        $result = $this->handler([], $features, $permissions)->handle(QueryMessage::create($query));
        self::assertSame(['prepared' => true, 'issues' => []], $result->toArray());
    }

    public function test_unexpected_payload_fields_and_wrong_query_type_reject_before_discovery(): void
    {
        try {
            ValidateFeaturePreparation::fromArray(['default_permission_name' => 'PREVIEW']);
            self::fail('Unknown fields cannot be silently discarded.');
        } catch (DomainException $domainException) {
            self::assertNotSame('', $domainException->getMessage());
        }

        $discovery = $this->createMock(FeatureReferenceDiscovery::class);
        $discovery->expects(self::never())->method('discover');
        $handler = new ValidateFeaturePreparationHandler(
            $discovery,
            $this->createStub(FeatureRepository::class),
            $this->createStub(PermissionRepository::class)
        );

        $this->expectException(DomainException::class);
        $handler->handle(QueryMessage::create(new GetPermissionById(PermissionId::generate())));
    }

    public function test_every_distinct_name_is_checked_and_invalid_configuration_is_safe_and_distinguishable(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unitOfWork);
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $valid = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('PREVIEW'),
            new DateTimeImmutable()
        );
        $replacement = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('REMOVED'),
            new DateTimeImmutable()
        );
        $permissions->add($valid);
        $permissions->add($replacement);

        $features->seed(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('off'),
            $valid->getId(),
            FeatureStatus::OFF,
            7
        ));
        $features->seed(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('on'),
            PermissionId::generate(),
            FeatureStatus::ON,
            9
        ));

        $names = ['off', 'missing', 'on', 'missing'];
        $result = $this->handler($names, $features, $permissions)
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));

        self::assertFalse($result->isPrepared());
        self::assertSame([
            ['name' => 'missing', 'problem' => 'missing_feature'],
            ['name' => 'on', 'problem' => 'broken_binding']
        ], $result->toArray()['issues']);
        self::assertSame(['off', 'missing', 'on'], $features->lookups);
        self::assertSame(0, $unitOfWork->transactions);
        self::assertSame(0, $features->writes);
        self::assertSame(FeaturePreparationProblem::BROKEN_BINDING, $result->getIssues()[1]->getProblem());
        self::assertSame('on', $result->getIssues()[1]->getName()->toString());
        self::assertSame('REMOVED', $replacement->getName()->toString());
    }

    public function test_incomplete_or_wrong_scope_discovery_rejects_before_catalog_access(): void
    {
        $results = [
            FeatureDiscoveryResult::unavailable(FeatureReferenceScope::CANDIDATE),
            FeatureDiscoveryResult::complete(FeatureReferenceScope::CURRENT, new FeatureReferences())
        ];
        foreach ($results as $discoveryResult) {
            $discovery = $this->createStub(FeatureReferenceDiscovery::class);
            $discovery->method('discover')->willReturn($discoveryResult);
            $features = $this->createMock(FeatureRepository::class);
            $features->expects(self::never())->method('getByName');
            $handler = new ValidateFeaturePreparationHandler(
                $discovery,
                $features,
                $this->createStub(PermissionRepository::class)
            );
            try {
                $handler->handle(QueryMessage::create(new ValidateFeaturePreparation()));
                self::fail('Incomplete discovery must not prepare candidate code.');
            } catch (FeatureDiscoveryException $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function test_invalid_native_declaration_after_a_valid_one_cannot_prepare_a_partial_inventory(): void
    {
        $code = new class {
            #[FeatureFlag('valid')]
            public function first(): void
            {
            }

            #[FeatureFlag('BAD_NAME')]
            public function second(): void
            {
            }
        };
        $discovery = new FixtureFeatureDiscovery($code, [], static fn(): bool => true);
        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::never())->method('getByName');
        $handler = new ValidateFeaturePreparationHandler(
            $discovery,
            $features,
            $this->createStub(PermissionRepository::class)
        );

        $this->expectException(FeatureDiscoveryException::class);
        $handler->handle(QueryMessage::create(new ValidateFeaturePreparation()));
    }

    public function test_invalid_persisted_definition_is_configuration_but_storage_failure_propagates(): void
    {
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturnCallback(static function (FeatureName $name): ?Feature {
            if ($name->toString() === 'invalid') {
                throw new FeatureStateException('Unsafe persisted bytes.');
            }

            if ($name->toString() === 'outage') {
                throw new RuntimeException('Storage unavailable.');
            }

            return null;
        });
        $permissions = $this->createStub(PermissionRepository::class);
        $result = $this->handler(['invalid', 'absent'], $features, $permissions)
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));
        self::assertSame([
            ['name' => 'invalid', 'problem' => 'invalid_definition'],
            ['name' => 'absent', 'problem' => 'missing_feature']
        ], $result->toArray()['issues']);

        $this->expectException(RuntimeException::class);
        $this->handler(['invalid', 'outage'], $features, $permissions)
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));
    }

    public function test_permission_storage_failure_is_not_reported_as_a_broken_binding(): void
    {
        $feature = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('dashboard'),
            PermissionId::generate()
        );
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturn($feature);
        $failure = new RuntimeException('Database connection unavailable.');
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willThrowException($failure);

        try {
            $this->handler(['dashboard'], $features, $permissions)
                ->handle(QueryMessage::create(new ValidateFeaturePreparation()));
            self::fail('An outage cannot yield a configuration result.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }
    }

    public function test_complete_inventory_exceeding_an_ordinary_page_checks_its_last_reference(): void
    {
        $names = [];
        for ($index = 1; $index <= 75; ++$index) {
            $names[] = 'flag-'.$index;
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unitOfWork);
        $result = $this->handler($names, $features, $this->createStub(PermissionRepository::class))
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));

        self::assertCount(75, $features->lookups);
        self::assertCount(75, $result->getIssues());
        self::assertSame('flag-75', $result->getIssues()[74]->getName()->toString());
    }

    public function test_mismatched_lookup_identities_reject_even_when_repositories_return_objects(): void
    {
        $wrongFeature = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('other'),
            PermissionId::generate()
        );
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturn($wrongFeature);
        $result = $this->handler(['requested'], $features, $this->createStub(PermissionRepository::class))
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));
        self::assertSame('invalid_definition', $result->toArray()['issues'][0]['problem']);

        $feature = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('requested'),
            PermissionId::generate()
        );
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturn($feature);
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willReturn(Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('PREVIEW'),
            new DateTimeImmutable()
        ));
        $result = $this->handler(['requested'], $features, $permissions)
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));
        self::assertSame('invalid_definition', $result->toArray()['issues'][0]['problem']);
    }

    /** @param list<string> $names */
    private function handler(
        array $names,
        FeatureRepository $features,
        PermissionRepository $permissions
    ): ValidateFeaturePreparationHandler {
        $discovery = $this->createMock(FeatureReferenceDiscovery::class);
        $discovery->expects(self::once())->method('discover')->with(FeatureReferenceScope::CANDIDATE)->willReturn(
            FeatureDiscoveryResult::complete(
                FeatureReferenceScope::CANDIDATE,
                FeatureReferences::fromStrings(...$names)
            )
        );

        return new ValidateFeaturePreparationHandler($discovery, $features, $permissions);
    }
}
