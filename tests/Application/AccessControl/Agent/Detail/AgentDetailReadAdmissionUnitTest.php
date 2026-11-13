<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Detail;

use Fight\AccessControl\Application\AccessControl\Agent\Detail\AgentDetailReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Detail\AgentDetailScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Detail\Service\DetailAccess;
use Fight\Common\Domain\Mcp\Detail\DetailSelection;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentDetailReadAdmission::class)]
final class AgentDetailReadAdmissionUnitTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool, bool}> */
    public static function permissions(): iterable
    {
        yield 'both metadata and body' => [['DETAIL_METADATA', 'DETAIL_BODY'], true, true];
        yield 'metadata only strips body' => [['DETAIL_METADATA'], true, false];
        yield 'body alone grants nothing' => [['DETAIL_BODY'], false, false];
        yield 'no direct permission grants nothing' => [[], false, false];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_direct_permissions_intersect_independent_scope(
        array $permissions,
        bool $metadata,
        bool $body
    ): void {
        $env = $this->environment($permissions);
        $selection = $this->selection();
        $access = DetailAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => ['producer/00000000-0000-4000-8000-000000000002']],
            ['project' => ['producer/00000000-0000-4000-8000-000000000002']]
        );
        $scope = $this->createMock(AgentDetailScope::class);
        $scope->expects($metadata ? self::once() : self::never())->method('currentAccess')
            ->with(self::callback(static fn(AuthenticatedAgentPrincipal $principal): bool =>
                $principal->getAgentId()->equals($env->original->getId())), $selection)
            ->willReturn($access);

        $result = $this->admission($env, $scope)->currentAccess($selection);

        self::assertSame($metadata ? $access->scope()->projects() : [], $result->scope()->projects());
        self::assertSame($metadata ? $access->scope()->phaseGrants() : [], $result->scope()->phaseGrants());
        self::assertSame($metadata ? $access->scope()->detailGrants() : [], $result->scope()->detailGrants());
        self::assertSame($body, $result->permitsBody('project', 'producer', '00000000-0000-4000-8000-000000000002'));
        self::assertFalse($result->permitsBody('project', 'producer', 'ungranted'));
    }

    public function test_direct_permission_does_not_widen_empty_consumer_scope(): void
    {
        $env = $this->environment(['DETAIL_METADATA', 'DETAIL_BODY']);
        $scope = $this->createStub(AgentDetailScope::class);
        $access = DetailAccess::create([]);
        $scope->method('currentAccess')->willReturn($access);

        self::assertSame($access, $this->admission($env, $scope)->currentAccess($this->selection()));
    }

    public function test_scope_fault_is_not_replaced_by_empty_success(): void
    {
        $env = $this->environment(['DETAIL_METADATA', 'DETAIL_BODY']);
        $scope = $this->createStub(AgentDetailScope::class);
        $failure = new RuntimeException('scope unavailable');
        $scope->method('currentAccess')->willThrowException($failure);
        $this->expectExceptionObject($failure);

        $this->admission($env, $scope)->currentAccess($this->selection());
    }

    public function test_every_metadata_requirement_is_required(): void
    {
        $env = $this->environment(['DETAIL_METADATA', 'DETAIL_BODY']);
        $scope = $this->createMock(AgentDetailScope::class);
        $scope->expects(self::never())->method('currentAccess');
        $admission = new AgentDetailReadAdmission(
            $env->provider,
            $env->request,
            $env->correlationId,
            $scope,
            [PermissionName::fromString('DETAIL_METADATA'), PermissionName::fromString('DETAIL_EXTRA')],
            [PermissionName::fromString('DETAIL_BODY')]
        );

        self::assertSame([], $admission->currentAccess($this->selection())->scope()->projects());
    }

    public function test_constructor_rejects_empty_body_requirements(): void
    {
        $env = $this->environment([]);
        $this->expectException(InvalidArgumentException::class);

        new AgentDetailReadAdmission(
            $env->provider,
            $env->request,
            $env->correlationId,
            $this->createStub(AgentDetailScope::class),
            [PermissionName::fromString('DETAIL_METADATA')],
            []
        );
    }

    /** @param list<string> $permissions */
    private function environment(array $permissions): ProfileToolEnvironment
    {
        // Existing in-memory repositories, fixed clock/verifier and modeled UoW; no database or transport.
        $env = new ProfileToolEnvironment();
        $agent = $env->storage->agentRepository->all()[0];
        foreach ($permissions as $name) {
            $id = PermissionId::generate();
            $env->permissionRepository->add(Permission::define($id, PermissionName::fromString($name), $env->now()));
            $agent = $agent->grantPermission($id, $env->now());
        }

        $env->storage->agentRepository->restoreSnapshot([$agent]);

        return $env;
    }

    private function selection(): DetailSelection
    {
        return DetailSelection::create(
            'project',
            'session',
            'phase',
            'producer',
            '00000000-0000-4000-8000-000000000001'
        );
    }

    private function admission(ProfileToolEnvironment $env, AgentDetailScope $scope): AgentDetailReadAdmission
    {
        return new AgentDetailReadAdmission(
            $env->provider,
            $env->request,
            $env->correlationId,
            $scope,
            [PermissionName::fromString('DETAIL_METADATA')],
            [PermissionName::fromString('DETAIL_BODY')]
        );
    }
}
