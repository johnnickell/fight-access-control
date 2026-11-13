<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Exception\AgentContinuationRejectedException;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCurrentReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosurePolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Task\Exception\TaskRejected;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentTaskCurrentReadAdmission::class)]
#[CoversClass(AgentTaskDisclosureGuard::class)]
#[CoversClass(AgentTaskDisclosureScope::class)]
final class AgentTaskDisclosureUnitTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function decisions(): iterable
    {
        yield 'both required' => [true, true];
        yield 'no signal' => [false, true];
        yield 'no complete-state grant' => [true, false];
    }

    #[DataProvider('decisions')]
    public function test_disclosure_requires_signal_and_exact_whole_state(bool $signal, bool $state): void
    {
        $fixture = new TaskAdmissionFixture();
        $principal = $fixture->provider->resolve($fixture->request, 'scope');
        $id = TaskId::fromString(TaskAdmissionFixture::ID);
        $proposal = ['status' => 'working', 'revision' => 2];
        $policy = $this->createMock(AgentTaskDisclosurePolicy::class);
        $policy->expects(self::once())->method('allowsSignal')
            ->with($principal, 'subscriber', $id)->willReturn($signal);
        $policy->expects($signal ? self::once() : self::never())->method('allowsState')
            ->with($principal, 'subscriber', $id, $proposal)->willReturn($state);
        $scope = new AgentTaskDisclosureScope('subscriber', [TaskAdmissionFixture::ID => $proposal], $policy);

        self::assertSame($policy, $scope->policy());
        self::assertSame($signal && $state, $scope->isAllowed($principal));
    }

    public function test_action_runs_under_original_bound_guard_with_exact_proposed_states(): void
    {
        $fixture = new TaskAdmissionFixture(['LISTEN', 'TASK_SIGNAL', 'TASK_READ']);
        $binding = AgentContinuationBinding::fromRequest($fixture->provider, $fixture->request, 'stream');
        $state = new class {
            public bool $held = false;
        };
        $fence = $this->createStub(AgentContinuationFence::class);
        $fence->method('synchronized')->willReturnCallback(
            static function (AgentId $id, AgentContinuationScope $scope, Closure $action) use ($state): void {
                self::assertInstanceOf(AgentTaskDisclosureScope::class, $scope);
                $state->held = true;
                try {
                    $action();
                } finally {
                    $state->held = false;
                }
            }
        );
        $policy = $this->createMock(AgentTaskDisclosurePolicy::class);
        $proposal = ['status' => 'working', 'revision' => 7];
        $policy->expects(self::once())->method('allowsSignal')->willReturn(true);
        $policy->expects(self::once())->method('allowsState')
            ->with(self::anything(), 'subscriber', TaskId::fromString(TaskAdmissionFixture::ID), $proposal)
            ->willReturn(true);
        $admission = new AgentTaskDisclosureGuard(
            'subscriber',
            new AgentContinuationGuard(
                $fixture->agentRepository,
                $fixture->permissionRepository,
                new FixedClock(TaskAdmissionFixture::now()),
                $fence
            ),
            $binding,
            array_map(PermissionName::fromString(...), ['LISTEN', 'TASK_SIGNAL', 'TASK_READ']),
            $policy
        );
        $actions = 0;
        $admission->run(
            'subscriber',
            [TaskAdmissionFixture::ID => $proposal],
            static function () use (&$actions, $state): void {
                self::assertTrue($state->held);
                ++$actions;
            }
        );
        self::assertSame(1, $actions);
        self::assertFalse($state->held);
        $this->expectException(AgentContinuationRejectedException::class);
        $admission->run('other-subscriber', [], static function (): void {
            self::fail('A different subscriber must never disclose.');
        });
    }

    public function test_live_reads_recheck_original_authority_and_comparison_scope(): void
    {
        $fixture = new TaskAdmissionFixture(['TASK_READ']);
        $binding = AgentContinuationBinding::fromRequest($fixture->provider, $fixture->request, 'live-read');
        $authority = new AgentNoticeAuthority(
            $binding,
            $fixture->agentRepository,
            $fixture->permissionRepository,
            new FixedClock(TaskAdmissionFixture::now()),
            [PermissionName::fromString('TASK_READ')]
        );
        $admission = new AgentTaskCurrentReadAdmission($authority, $fixture);
        $id = TaskId::fromString(TaskAdmissionFixture::ID);

        self::assertTrue($admission->allowsRead('scope', $id));
        self::assertSame('reader-visibility-v1', $admission->comparisonScope('scope', $id));
        $fixture->read = false;
        self::assertFalse($admission->allowsRead('scope', $id));
        $this->expectException(TaskRejected::class);
        $admission->comparisonScope('scope', $id);
    }
}
