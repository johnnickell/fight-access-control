<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationObservationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCancellationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskRemovalAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskRemovalScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentTaskAuthority::class)]
#[CoversClass(AgentTaskContinuationScope::class)]
#[CoversClass(AgentTaskCreationAdmission::class)]
#[CoversClass(AgentTaskUpdateAdmission::class)]
#[CoversClass(AgentTaskReadAdmission::class)]
#[CoversClass(AgentTaskRemovalAdmission::class)]
#[CoversClass(AgentTaskCancellationAdmission::class)]
final class AgentTaskAdmissionUnitTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function admission(): iterable
    {
        yield 'permission and scope' => [true, true];
        yield 'scope without permission' => [false, true];
        yield 'permission without scope' => [true, false];
        yield 'neither' => [false, false];
    }

    #[DataProvider('admission')]
    public function test_create_update_and_read_keep_independent_permissions_and_scope(bool $grant, bool $scope): void
    {
        $fixture = new TaskAdmissionFixture($grant ? ['TASK_CREATE', 'TASK_UPDATE', 'TASK_READ'] : []);
        $fixture->create = $scope;
        $fixture->update = $scope;
        $fixture->read = $scope;

        $id = TaskId::fromString(TaskAdmissionFixture::ID);

        self::assertSame($grant && $scope, new AgentTaskCreationAdmission(
            $fixture->authority('TASK_CREATE'),
            $fixture
        )->allowsCreation(TaskAdmissionFixture::definition()));
        self::assertSame($grant && $scope, new AgentTaskUpdateAdmission(
            $fixture->authority('TASK_UPDATE'),
            $fixture
        )->allowsUpdate('scope', $id, 'producer'));
        $read = new AgentTaskReadAdmission($fixture->authority('TASK_READ'), $fixture);
        self::assertSame($grant && $scope, $read->allowsRead('scope', $id));
        if ($grant && $scope) {
            self::assertSame('reader-visibility-v1', $read->comparisonScope('scope', $id));
            self::assertFalse($read->allowsRead('other', $id));
        } else {
            $this->expectException(TaskRejected::class);
            $read->comparisonScope('scope', $id);
        }
    }

    #[DataProvider('admission')]
    public function test_removal_passes_exact_target_only_after_its_own_permission(bool $grant, bool $allowed): void
    {
        $fixture = new TaskAdmissionFixture($grant ? ['TASK_REMOVE'] : []);
        $id = TaskId::fromString(TaskAdmissionFixture::ID);
        $scope = $this->createMock(AgentTaskRemovalScope::class);
        $scope->expects($grant ? self::once() : self::never())->method('allowsRemoval')
            ->with(self::anything(), 'target-scope', $id)->willReturn($allowed);

        self::assertSame($grant && $allowed, new AgentTaskRemovalAdmission(
            $fixture->authority('TASK_REMOVE'),
            $scope
        )->allowsRemoval('target-scope', $id));
    }

    public function test_cancellation_mutation_and_observation_require_separate_authority(): void
    {
        $fixture = new TaskAdmissionFixture(['TASK_CANCEL']);
        $id = TaskId::fromString(TaskAdmissionFixture::ID);
        $cancel = $this->createMock(AgentTaskCancellationScope::class);
        $cancel->expects(self::once())->method('allowsCancellation')
            ->with(self::anything(), 'scope', $id, 'caller')->willReturn(true);
        $observe = $this->createMock(AgentTaskCancellationObservationScope::class);
        $observe->expects(self::never())->method('allowsCancellationObservation');
        $admission = new AgentTaskCancellationAdmission(
            $fixture->authority('TASK_CANCEL'),
            $cancel,
            $fixture->authority('INTENT_OBSERVE'),
            $observe
        );

        self::assertTrue($admission->allowsCancellation('scope', $id, 'caller'));
        self::assertFalse($admission->allowsCancellationObservation('scope', $id, 'caller'));
        $observer = new TaskAdmissionFixture(['INTENT_OBSERVE']);
        $observe = $this->createMock(AgentTaskCancellationObservationScope::class);
        $observe->expects(self::once())->method('allowsCancellationObservation')
            ->with(self::anything(), 'scope', $id, 'caller')->willReturn(true);
        $admission = new AgentTaskCancellationAdmission(
            $observer->authority('TASK_CANCEL'),
            $cancel,
            $observer->authority('INTENT_OBSERVE'),
            $observe
        );
        self::assertFalse($admission->allowsCancellation('scope', $id, 'caller'));
        self::assertTrue($admission->allowsCancellationObservation('scope', $id, 'caller'));
    }

    public function test_authentication_refusal_is_absence_but_resolution_fault_is_not(): void
    {
        $invalid = new TaskAdmissionFixture(validSignature: false);
        self::assertNull($invalid->authority('TASK_READ')->principal());
        $broken = new TaskAdmissionFixture(complete: false);
        $this->expectException(CurrentAgentPrincipalResolutionRejectedException::class);

        $broken->authority('TASK_READ')->principal();
    }

    public function test_scope_fault_keeps_its_identity_instead_of_becoming_refusal(): void
    {
        $fixture = new TaskAdmissionFixture();
        $fixture->fault = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Private consumer policy unavailable.');

        new AgentTaskReadAdmission($fixture->authority('TASK_READ'), $fixture)
            ->allowsRead('scope', TaskId::fromString(TaskAdmissionFixture::ID));
    }

    public function test_continuation_authority_expires_after_success_or_action_failure(): void
    {
        foreach ([false, true] as $fail) {
            $fixture = new TaskAdmissionFixture(['TASK_UPDATE']);
            $binding = AgentContinuationBinding::fromRequest($fixture->provider, $fixture->request, 'unit');
            $scope = $this->createStub(AgentContinuationScope::class);
            $scope->method('isAllowed')->willReturn(true);
            $fence = $this->createMock(AgentContinuationFence::class);
            $fence->expects(self::once())->method('synchronized')->willReturnCallback(
                static function (AgentId $id, AgentContinuationScope $wrapped, Closure $action) use ($scope): void {
                    self::assertInstanceOf(AgentTaskContinuationScope::class, $wrapped);
                    self::assertSame($scope, $wrapped->getScope());
                    $action();
                }
            );
            $guard = new AgentContinuationGuard(
                $fixture->agentRepository,
                $fixture->permissionRepository,
                new FixedClock(TaskAdmissionFixture::now()),
                $fence
            );
            $saved = null;
            $failure = new RuntimeException('action failed');
            try {
                AgentTaskAuthority::duringContinuation(
                    $guard,
                    $binding,
                    [PermissionName::fromString('TASK_UPDATE')],
                    $scope,
                    static function (AgentTaskAuthority $authority) use (&$saved, $fixture, $fail, $failure): void {
                        $saved = $authority;
                        self::assertTrue($authority->principal()->getAgentId()->equals($fixture->agent->getId()));
                        if ($fail) {
                            throw $failure;
                        }
                    }
                );
                self::assertFalse($fail);
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }

            self::assertInstanceOf(AgentTaskAuthority::class, $saved);
            try {
                $saved->principal();
                self::fail('Authority must not escape the synchronous action.');
            } catch (AgentContinuationRejectedException) {
                self::assertSame(1, $fixture->nonce->consumptionCalls());
            }
        }
    }
}
