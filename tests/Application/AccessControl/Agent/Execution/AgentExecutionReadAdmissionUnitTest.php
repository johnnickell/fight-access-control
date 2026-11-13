<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Execution;

use Fight\AccessControl\Application\AccessControl\Agent\Execution\AgentExecutionReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Execution\AgentExecutionScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Session\Service\ExecutionAccess;
use Fight\Common\Domain\Mcp\Session\ExecutionSelection;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentExecutionReadAdmission::class)]
final class AgentExecutionReadAdmissionUnitTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool, bool}> */
    public static function permissions(): iterable
    {
        yield 'both' => [['READ_TIMELINE', 'READ_REFERENCE'], true, true];
        yield 'timeline only' => [['READ_TIMELINE'], true, false];
        yield 'reference alone' => [['READ_REFERENCE'], false, false];
        yield 'neither' => [[], false, false];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_permission_intersection_preserves_narrow_scope(
        array $permissions,
        bool $read,
        bool $reference
    ): void {
        $env = $this->environment($permissions);
        $selection = ExecutionSelection::create(['project']);
        $access = ExecutionAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => ['producer/00000000-0000-4000-8000-000000000001']],
            ['project' => ['producer/00000000-0000-4000-8000-000000000002']]
        );
        $scope = $this->createMock(AgentExecutionScope::class);
        $scope->expects($read ? self::once() : self::never())->method('currentAccess')
            ->with(self::callback(static fn(AuthenticatedAgentPrincipal $principal): bool =>
                $principal->getAgentId()->equals($env->original->getId())), $selection)->willReturn($access);
        $result = $this->admission($env, $scope)->currentAccess($selection);

        self::assertSame($read ? $access->projects() : [], $result->projects());
        self::assertSame($read ? $access->phaseGrants() : [], $result->phaseGrants());
        self::assertSame($read ? $access->activityGrants() : [], $result->activityGrants());
        self::assertSame($reference ? $access->detailGrants() : [], $result->detailGrants());
        if ($reference) {
            self::assertSame($access, $result);
        }
    }

    public function test_permission_does_not_widen_empty_consumer_decision(): void
    {
        $env = $this->environment(['READ_TIMELINE', 'READ_REFERENCE']);
        $access = ExecutionAccess::create([]);
        $scope = $this->createStub(AgentExecutionScope::class);
        $scope->method('currentAccess')->willReturn($access);
        self::assertSame(
            $access,
            $this->admission($env, $scope)->currentAccess(ExecutionSelection::create(['project']))
        );
    }

    public function test_scope_fault_propagates_without_fabricated_denial(): void
    {
        $env = $this->environment(['READ_TIMELINE']);
        $scope = $this->createStub(AgentExecutionScope::class);
        $failure = new RuntimeException('private policy fault');
        $scope->method('currentAccess')->willThrowException($failure);
        $this->expectExceptionObject($failure);
        $this->admission($env, $scope)->currentAccess(ExecutionSelection::create(['project']));
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function declarations(): iterable
    {
        $read = PermissionName::fromString('READ_TIMELINE');
        $reference = PermissionName::fromString('READ_REFERENCE');
        foreach ([[], ['READ_TIMELINE'], [$read, $read], ['key' => $read], [1]] as $i => $invalid) {
            yield 'read-'.$i => [$invalid, [$reference]];
            yield 'reference-'.$i => [[$read], $invalid];
        }
    }

    /**
     * @param array<mixed> $read
     * @param array<mixed> $reference
     */
    #[DataProvider('declarations')]
    public function test_invalid_declarations_reject_composition(array $read, array $reference): void
    {
        $env = $this->environment([]);
        $this->expectException(InvalidArgumentException::class);
        new AgentExecutionReadAdmission(
            $env->provider,
            $env->request,
            $env->correlationId,
            $this->createStub(AgentExecutionScope::class),
            $read,
            $reference
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function extraRequirements(): iterable
    {
        yield 'read conjunction' => [true];
        yield 'reference conjunction' => [false];
    }

    #[DataProvider('extraRequirements')]
    public function test_every_direct_requirement_is_required(bool $readExtra): void
    {
        $env = $this->environment(['READ_TIMELINE', 'READ_REFERENCE']);
        $scope = $this->createMock(AgentExecutionScope::class);
        $scope->expects($readExtra ? self::never() : self::once())->method('currentAccess')->willReturn(
            ExecutionAccess::create(['project' => null], [], [], ['project' => null])
        );
        $read = [PermissionName::fromString('READ_TIMELINE')];
        $reference = [PermissionName::fromString('READ_REFERENCE')];
        if ($readExtra) {
            $read[] = PermissionName::fromString('READ_EXTRA');
        } else {
            $reference[] = PermissionName::fromString('REFERENCE_EXTRA');
        }

        $admission = new AgentExecutionReadAdmission(
            $env->provider,
            $env->request,
            $env->correlationId,
            $scope,
            $read,
            $reference
        );
        $result = $admission->currentAccess(ExecutionSelection::create(['project']));
        self::assertSame($readExtra ? [] : ['project' => null], $result->projects());
        self::assertSame([], $result->detailGrants());
    }

    /** @param list<string> $names */
    private function environment(array $names): ProfileToolEnvironment
    {
        $env = new ProfileToolEnvironment();
        $agent = $env->storage->agentRepository->all()[0];
        foreach ($names as $name) {
            $id = PermissionId::generate();
            $env->permissionRepository->add(Permission::define($id, PermissionName::fromString($name), $env->now()));
            $agent = $agent->grantPermission($id, $env->now());
        }

        $env->storage->agentRepository->restoreSnapshot([$agent]);

        return $env;
    }

    private function admission(ProfileToolEnvironment $env, AgentExecutionScope $scope): AgentExecutionReadAdmission
    {
        return new AgentExecutionReadAdmission(
            $env->provider,
            $env->request,
            $env->correlationId,
            $scope,
            [PermissionName::fromString('READ_TIMELINE')],
            [PermissionName::fromString('READ_REFERENCE')]
        );
    }
}
