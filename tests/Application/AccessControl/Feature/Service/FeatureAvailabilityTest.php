<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalRole;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureBindingException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FeatureAvailability::class)]
#[CoversClass(FeatureStatus::class)]
#[CoversClass(FeatureNotFoundException::class)]
#[CoversClass(FeatureBindingException::class)]
final class FeatureAvailabilityTest extends TestCase
{
    public function test_status_matrix_uses_captured_permission_identity_not_names_or_roles(): void
    {
        $unit = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unit);
        $permissions = new InMemoryPermissionRepository($unit);
        $id = PermissionId::generate();
        $otherId = PermissionId::generate();
        $permissions->add(Permission::defineManaged(
            $id,
            PermissionName::fromString('PROTECTED'),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable()
        ));
        $service = new FeatureAvailability($features, $permissions);
        $user = $this->user([new PrincipalPermission($id, PermissionName::fromString('OLD_NAME'))]);
        $agent = $this->agent([new PrincipalPermission($otherId, PermissionName::fromString('PROTECTED'))]);
        $noGrants = $this->user([], true);

        foreach ([FeatureStatus::OFF, FeatureStatus::PREVIEW, FeatureStatus::ON] as $status) {
            $current = FeatureName::fromString('feature-'.$status->value);
            $features->seed(Feature::reconstitute(FeatureId::generate(), $current, $id, $status, 1));
            self::assertSame($status !== FeatureStatus::OFF, $service->isAvailable($current, $user));
            self::assertSame($status === FeatureStatus::ON, $service->isAvailable($current, $agent));
            self::assertSame($status === FeatureStatus::ON, $service->isAvailable($current, $noGrants));
            self::assertSame($status === FeatureStatus::ON, $service->isAvailable($current, null));
        }

        self::assertSame(0, $unit->transactions);
        self::assertSame(0, $features->writes);
        self::assertCount(12, $features->lookups);
    }

    public function test_shared_and_distinct_preview_cohorts_follow_only_the_bound_identity(): void
    {
        [$service, $features, $permissions, $first] = $this->fixture();
        $second = PermissionId::generate();
        $permissions->add(Permission::define($first, PermissionName::fromString('FIRST'), new DateTimeImmutable()));
        $permissions->add(Permission::define($second, PermissionName::fromString('SECOND'), new DateTimeImmutable()));
        foreach (['shared', 'distinct'] as $name) {
            $features->seed(Feature::reconstitute(
                FeatureId::generate(),
                FeatureName::fromString($name),
                $name === 'shared' ? $first : $second,
                FeatureStatus::PREVIEW,
                1
            ));
        }

        $user = $this->user([new PrincipalPermission($first, PermissionName::fromString('FIRST'))]);
        $agent = $this->agent([new PrincipalPermission($first, PermissionName::fromString('FIRST'))]);
        self::assertTrue($service->isAvailable(FeatureName::fromString('shared'), $user));
        self::assertTrue($service->isAvailable(FeatureName::fromString('shared'), $agent));
        self::assertFalse($service->isAvailable(FeatureName::fromString('distinct'), $user));
        self::assertFalse($service->isAvailable(FeatureName::fromString('distinct'), $agent));
    }

    public function test_agent_with_direct_identity_previews_without_a_role_or_name_match(): void
    {
        [$service, $features, $permissions, $id] = $this->fixture();
        $permissions->add(Permission::define($id, PermissionName::fromString('RENAMED'), new DateTimeImmutable()));
        $name = FeatureName::fromString('preview');
        $features->seed(Feature::reconstitute(FeatureId::generate(), $name, $id, FeatureStatus::PREVIEW, 1));
        self::assertTrue($service->isAvailable($name, $this->agent([
            new PrincipalPermission($id, PermissionName::fromString('OLD_NAME'))
        ])));
    }

    public function test_absence_broken_binding_and_invalid_stored_definitions_are_not_boolean_results(): void
    {
        [$service, $features, $permissions, $id] = $this->fixture();
        $name = FeatureName::fromString('missing');
        try {
            $service->isAvailable($name, null);
            self::fail('Unknown Features cannot be ordinary unavailable.');
        } catch (FeatureNotFoundException) {
            self::assertSame(0, $features->writes);
        }

        foreach (FeatureStatus::cases() as $status) {
            $current = FeatureName::fromString('broken-'.$status->value);
            $features->seed(Feature::reconstitute(FeatureId::generate(), $current, $id, $status, 1));
            try {
                $service->isAvailable($current, null);
                self::fail('A missing bound Permission is invalid even for OFF or ON.');
            } catch (FeatureBindingException) {
                self::assertSame(0, $features->writes);
            }
        }

        $permissions->add(Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('REUSED'),
            new DateTimeImmutable()
        ));
        $this->expectException(FeatureBindingException::class);
        $service->isAvailable(FeatureName::fromString('broken-on'), $this->user([
            new PrincipalPermission($id, PermissionName::fromString('REUSED'))
        ]));
    }

    public function test_invalid_names_and_malformed_persisted_state_fail_without_catalog_mutation(): void
    {
        [, $features] = $this->fixture();
        try {
            FeatureName::fromString('BAD NAME');
            self::fail('Invalid Feature names must reject before service evaluation.');
        } catch (FeatureNameException) {
            self::assertSame([], $features->lookups);
        }

        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willThrowException(new FeatureStateException('Invalid persisted definition.'));
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::never())->method('getById');
        $this->expectException(FeatureStateException::class);
        new FeatureAvailability($features, $permissions)->isAvailable(FeatureName::fromString('flag'), null);
    }

    public function test_mismatched_repository_objects_and_hydration_fail_closed(): void
    {
        $name = FeatureName::fromString('requested');
        $id = PermissionId::generate();
        $wrong = Feature::define(FeatureId::generate(), FeatureName::fromString('different'), $id);
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturn($wrong);
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::never())->method('getById');
        $service = new FeatureAvailability($features, $permissions);
        try {
            $service->isAvailable($name, null);
            self::fail('Mismatched Feature name must fail.');
        } catch (FeatureStateException) {
        }

        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturn(Feature::define(FeatureId::generate(), $name, $id));
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willReturn(Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('PREVIEW'),
            new DateTimeImmutable()
        ));
        $this->expectException(FeatureStateException::class);
        new FeatureAvailability($features, $permissions)->isAvailable($name, null);
    }

    public function test_storage_failures_and_invalid_input_do_not_provision_or_hide_outages(): void
    {
        $features = $this->createStub(FeatureRepository::class);
        $failure = new RuntimeException('Unavailable');
        $features->method('getByName')->willThrowException($failure);
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->expects(self::never())->method('getById');
        try {
            new FeatureAvailability($features, $permissions)->isAvailable(FeatureName::fromString('flag'), null);
            self::fail('Infrastructure failure must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('flag'), PermissionId::generate());
        $features = $this->createStub(FeatureRepository::class);
        $features->method('getByName')->willReturn($feature);
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willThrowException($failure);
        try {
            new FeatureAvailability($features, $permissions)->isAvailable($feature->getName(), null);
            self::fail('Permission storage failure must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }
    }

    /** @return array{FeatureAvailability, InMemoryFeatureRepository, InMemoryPermissionRepository, PermissionId} */
    private function fixture(): array
    {
        $unit = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unit);
        $permissions = new InMemoryPermissionRepository($unit);

        return [new FeatureAvailability($features, $permissions), $features, $permissions, PermissionId::generate()];
    }

    /** @param list<PrincipalPermission> $permissions */
    private function user(array $permissions, bool $superAdmin = false): AuthenticatedUserPrincipal
    {
        return new AuthenticatedUserPrincipal(
            UserId::generate(),
            RefreshSessionId::generate(),
            1,
            $superAdmin ? [new PrincipalRole(RoleId::generate(), RoleName::fromString('ROLE_SUPER_ADMIN'))] : [],
            $permissions
        );
    }

    /** @param list<PrincipalPermission> $permissions */
    private function agent(array $permissions): AuthenticatedAgentPrincipal
    {
        return new AuthenticatedAgentPrincipal(AgentId::generate(), AgentCredentialId::generate(), 1, 1, $permissions);
    }
}
