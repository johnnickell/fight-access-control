<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionPlanItem;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRolePlanItem;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(PreviewManagedPolicyHandler::class)]
#[CoversClass(ManagedPolicyPlanner::class)]
#[CoversClass(ManagedPermissionPlanItem::class)]
#[CoversClass(ManagedPolicyChangeAction::class)]
#[CoversClass(ManagedPolicyDefinitionException::class)]
#[CoversClass(ManagedPolicyPlan::class)]
#[CoversClass(ManagedRolePlanItem::class)]
#[CoversClass(PreviewManagedPolicy::class)]
final class PreviewManagedPolicyHandlerTest extends TestCase
{
    public function test_it_returns_a_deterministic_complete_plan_without_writes_commits_or_events(): void
    {
        $authorizationReferences = new InMemoryAuthorizationReferenceState();
        $permissionRepository = new InMemoryPermissionRepository(
            authorizationReferences: $authorizationReferences
        );
        $permissionRepository->add(Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000101'),
            PermissionName::fromString('VIEW_USERS'),
            PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        ));
        $permissionRepository->add(Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000102'),
            PermissionName::fromString('OLD_MANAGE_USERS'),
            PermissionTier::SUPER_ADMIN_ONLY,
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        ));
        $roleRepository = new InMemoryRoleRepository(authorizationReferences: $authorizationReferences);
        $roleRepository->add(Role::defineManaged(
            RoleId::fromString('018f0000-0000-7000-8000-000000000201'),
            RoleName::fromString('ROLE_VIEWER'),
            [PermissionId::fromString('018f0000-0000-7000-8000-000000000101')],
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        ));
        $roleRepository->add(Role::defineManaged(
            RoleId::fromString('018f0000-0000-7000-8000-000000000202'),
            RoleName::fromString('ROLE_EDITOR'),
            [PermissionId::fromString('018f0000-0000-7000-8000-000000000101')],
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        ));
        $query = $this->preview(
            [
                $this->permission('018f0000-0000-7000-8000-000000000101', 'VIEW_USERS', 'ADMIN_SAFE'),
                $this->permission('018f0000-0000-7000-8000-000000000102', 'MANAGE_USERS', 'SUPER_ADMIN_ONLY'),
                $this->permission('018f0000-0000-7000-8000-000000000103', 'ARCHIVE_USERS', 'ADMIN_SAFE'),
            ],
            [
                $this->role(
                    '018f0000-0000-7000-8000-000000000201',
                    'ROLE_VIEWER',
                    ['018f0000-0000-7000-8000-000000000101']
                ),
                $this->role(
                    '018f0000-0000-7000-8000-000000000203',
                    'ROLE_ADMIN',
                    [
                        '018f0000-0000-7000-8000-000000000102',
                        '018f0000-0000-7000-8000-000000000103',
                    ]
                ),
                $this->role(
                    '018f0000-0000-7000-8000-000000000202',
                    'ROLE_EDITOR',
                    [
                        '018f0000-0000-7000-8000-000000000101',
                        '018f0000-0000-7000-8000-000000000102',
                    ]
                ),
            ]
        );
        $handler = new PreviewManagedPolicyHandler(
            new ManagedPolicyPlanner(
                $permissionRepository,
                $roleRepository,
                new InMemoryUserRepository()
            )
        );

        $plan = $handler->handle(QueryMessage::create($query));

        self::assertSame(PreviewManagedPolicy::class, PreviewManagedPolicyHandler::queryRegistration());
        self::assertInstanceOf(ManagedPolicyPlan::class, $plan);
        self::assertSame(
            ['ARCHIVE_USERS', 'MANAGE_USERS', 'VIEW_USERS'],
            array_map(
                static fn(ManagedPermissionPlanItem $item): string => $item->getDefinition()->getName()->toString(),
                $plan->getPermissions()
            )
        );
        self::assertSame(
            ['CREATE', 'RECONCILE', 'UNCHANGED'],
            array_map(
                static fn(ManagedPermissionPlanItem $item): string => $item->getAction()->value,
                $plan->getPermissions()
            )
        );
        self::assertSame(
            ['ROLE_ADMIN', 'ROLE_EDITOR', 'ROLE_VIEWER'],
            array_map(
                static fn(ManagedRolePlanItem $item): string => $item->getDefinition()->getName()->toString(),
                $plan->getRoles()
            )
        );
        self::assertSame(
            ['CREATE', 'RECONCILE', 'UNCHANGED'],
            array_map(
                static fn(ManagedRolePlanItem $item): string => $item->getAction()->value,
                $plan->getRoles()
            )
        );
        self::assertSame(2, $permissionRepository->getAll(new Pagination())->totalRecords());
        self::assertSame(2, $roleRepository->getAll(new Pagination())->totalRecords());
        self::assertSame(
            ['managedPolicyPlanner'],
            array_map(
                static fn(ReflectionProperty $property): string => $property->getName(),
                new ReflectionClass($handler)->getProperties()
            )
        );
    }

    public function test_the_query_round_trips_and_rejects_missing_or_non_array_collections(): void
    {
        $query = $this->preview(
            [$this->permission('018f0000-0000-7000-8000-000000000101', 'VIEW_USERS', 'ADMIN_SAFE')],
            [$this->role(
                '018f0000-0000-7000-8000-000000000201',
                'ROLE_VIEWER',
                ['018f0000-0000-7000-8000-000000000101']
            )]
        );

        self::assertEquals($query, PreviewManagedPolicy::fromArray($query->toArray()));
        self::assertCount(1, $query->getPolicy()->getPermissions());
        self::assertCount(1, $query->getPolicy()->getRoles());
        $rejections = 0;

        foreach (['permissions', 'roles'] as $field) {
            $incomplete = $query->toArray();
            unset($incomplete[$field]);

            try {
                PreviewManagedPolicy::fromArray($incomplete);
                self::fail('A missing policy definition collection must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }

            $invalid = $query->toArray();
            $invalid[$field] = 'not-an-array';

            try {
                PreviewManagedPolicy::fromArray($invalid);
                self::fail('A non-array policy definition collection must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        self::assertSame(4, $rejections);
    }

    public function test_it_rejects_duplicate_definition_ids_and_names_and_unknown_membership(): void
    {
        $permission = $this->permission(
            '018f0000-0000-7000-8000-000000000101',
            'VIEW_USERS',
            'ADMIN_SAFE'
        );
        $role = $this->role('018f0000-0000-7000-8000-000000000201', 'ROLE_VIEWER', []);
        $rejections = 0;
        $invalidQueries = [
            [
                [$permission, $this->permission(
                    '018f0000-0000-7000-8000-000000000101',
                    'MANAGE_USERS',
                    'SUPER_ADMIN_ONLY'
                )],
                [],
            ],
            [
                [$permission, $this->permission(
                    '018f0000-0000-7000-8000-000000000102',
                    'VIEW_USERS',
                    'SUPER_ADMIN_ONLY'
                )],
                [],
            ],
            [
                [],
                [$role, $this->role(
                    '018f0000-0000-7000-8000-000000000201',
                    'ROLE_ADMIN',
                    []
                )],
            ],
            [
                [],
                [$role, $this->role(
                    '018f0000-0000-7000-8000-000000000202',
                    'ROLE_VIEWER',
                    []
                )],
            ],
            [
                [],
                [$this->role(
                    '018f0000-0000-7000-8000-000000000201',
                    'ROLE_VIEWER',
                    ['018f0000-0000-7000-8000-000000000199']
                )],
            ],
        ];

        foreach ($invalidQueries as [$permissions, $roles]) {
            try {
                new ManagedPolicy($permissions, $roles);
                self::fail('Ambiguous managed definitions must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                ++$rejections;
            }
        }

        self::assertSame(5, $rejections);
    }

    public function test_it_rejects_persisted_permission_and_role_name_collisions(): void
    {
        $permissionRepository = new InMemoryPermissionRepository();
        $permissionRepository->add(Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000111'),
            PermissionName::fromString('VIEW_USERS'),
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        ));
        $roleRepository = new InMemoryRoleRepository();
        $roleRepository->add(Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000211'),
            RoleName::fromString('ROLE_VIEWER'),
            [],
            new DateTimeImmutable('2026-08-01T00:00:00+00:00')
        ));
        $handler = new PreviewManagedPolicyHandler(
            new ManagedPolicyPlanner(
                $permissionRepository,
                $roleRepository,
                new InMemoryUserRepository()
            )
        );

        try {
            $handler->handle(QueryMessage::create($this->preview(
                [$this->permission(
                    '018f0000-0000-7000-8000-000000000101',
                    'VIEW_USERS',
                    'ADMIN_SAFE'
                )],
                []
            )));
            self::fail('A persisted permission name collision must be rejected.');
        } catch (ManagedPolicyDefinitionException $managedPolicyDefinitionException) {
            self::assertSame(
                'Permission name "VIEW_USERS" belongs to persisted identifier "018f0000-0000-7000-8000-000000000111".',
                $managedPolicyDefinitionException->getMessage()
            );
        }

        try {
            $handler->handle(QueryMessage::create($this->preview(
                [],
                [$this->role(
                    '018f0000-0000-7000-8000-000000000201',
                    'ROLE_VIEWER',
                    []
                )]
            )));
            self::fail('A persisted role name collision must be rejected.');
        } catch (ManagedPolicyDefinitionException $managedPolicyDefinitionException) {
            self::assertSame(
                'Role name "ROLE_VIEWER" belongs to persisted identifier "018f0000-0000-7000-8000-000000000211".',
                $managedPolicyDefinitionException->getMessage()
            );
        }

        self::assertSame(1, $permissionRepository->getAll(new Pagination())->totalRecords());
        self::assertSame(1, $roleRepository->getAll(new Pagination())->totalRecords());
    }

    private function permission(string $id, string $name, string $tier): ManagedPermissionDefinition
    {
        return new ManagedPermissionDefinition(
            PermissionId::fromString($id),
            PermissionName::fromString($name),
            PermissionTier::from($tier)
        );
    }

    /**
     * @param list<ManagedPermissionDefinition> $permissions
     * @param list<ManagedRoleDefinition> $roles
     */
    private function preview(array $permissions, array $roles): PreviewManagedPolicy
    {
        return new PreviewManagedPolicy(new ManagedPolicy($permissions, $roles));
    }

    /**
     * @param list<string> $permissionIds
     */
    private function role(string $id, string $name, array $permissionIds): ManagedRoleDefinition
    {
        return new ManagedRoleDefinition(
            RoleId::fromString($id),
            RoleName::fromString($name),
            array_map(
                PermissionId::fromString(...),
                $permissionIds
            )
        );
    }
}
