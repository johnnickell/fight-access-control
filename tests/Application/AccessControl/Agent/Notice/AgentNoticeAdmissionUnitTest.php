<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use DateInterval;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticePolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticePublicationPolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticePublicationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadPolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeRemovalAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Mcp\Notice\Data\NoticeView;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeRejected;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeRemovalRejected;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationGrant;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeRemovalSelection;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentNoticeAuthority::class)]
#[CoversClass(AgentNoticeAdmission::class)]
#[CoversClass(AgentNoticeRemovalAdmission::class)]
#[CoversClass(AgentNoticeReadAdmission::class)]
#[CoversClass(AgentNoticePublicationPolicy::class)]
final class AgentNoticeAdmissionUnitTest extends TestCase
{
    private TaskAdmissionFixture $fixture;

    private AgentContinuationBinding $binding;

    private ?Agent $current;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->fixture = new TaskAdmissionFixture(['NOTICE']);
        $this->binding = AgentContinuationBinding::fromRequest(
            $this->fixture->provider,
            $this->fixture->request,
            'unit-notice'
        );
        $this->current = $this->fixture->agent;
        $this->clock = new FixedClock(TaskAdmissionFixture::now());
    }

    /** @return iterable<string, array{string}> */
    public static function changes(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'revoked' => ['revoked'];
        yield 'permission lost' => ['permission'];
        yield 'expired original binding' => ['expired'];
    }

    #[DataProvider('changes')]
    public function test_authority_is_resolved_fresh_without_replaying_nonce(string $change): void
    {
        $authority = $this->authority();
        self::assertTrue($authority->resolve()->getAgentId()->equals($this->fixture->agent->getId()));
        $this->current = match ($change) {
            'missing' => null,
            'revoked' => $this->current->revoke(TaskAdmissionFixture::now()),
            'permission' => $this->current->revokePermission(
                $this->current->getPermissionIds()[0],
                TaskAdmissionFixture::now()
            ),
            default => $this->current
        };
        if ($change === 'expired') {
            $this->clock = new FixedClock(TaskAdmissionFixture::now()->add(new DateInterval('PT1H')));
            $authority = $this->authority();
        }

        self::assertNull($authority->resolve());
        self::assertSame(1, $this->fixture->nonce->consumptionCalls());
    }

    public function test_wrong_identity_is_an_internal_fault_not_refusal(): void
    {
        $this->current = new TaskAdmissionFixture(identity: 901)->agent;
        $this->expectException(LogicException::class);

        $this->authority()->resolve();
    }

    public function test_protected_permission_definition_is_an_internal_fault(): void
    {
        $original = $this->fixture->permissionRepository->getByName(PermissionName::fromString('NOTICE'));
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getByIds')->willReturn([Permission::defineManaged(
            $original->getId(),
            $original->getName(),
            PermissionTier::SUPER_ADMIN_ONLY,
            TaskAdmissionFixture::now()
        )]);
        $this->expectException(LogicException::class);

        $this->authority($permissions)->resolve();
    }

    public function test_recording_and_both_removal_admissions_keep_independent_consumer_policy(): void
    {
        $notice = $this->notice();
        $selection = NoticeRemovalSelection::fromArray([
            'version'   => 1,
            'namespace' => str_repeat('a', 32),
            'targets'   => [['scope' => 'scope', 'producer' => 'producer', 'id' => 'notice', 'position' => 1]]
        ]);
        $policy = $this->createMock(AgentNoticePolicy::class);
        $policy->expects(self::once())->method('allowsRecording')->with(self::anything(), $notice)->willReturn(true);
        $policy->expects(self::once())->method('allowsRemoval')->with(self::anything(), $selection)->willReturn(true);
        $policy->expects(self::once())->method('allowsRetainedRemoval')
            ->with(self::anything(), $notice)->willReturn(true);
        new AgentNoticeAdmission($this->authority(), $policy)->admit($notice);
        $removal = new AgentNoticeRemovalAdmission($this->authority(), $policy);
        $removal->admit($selection);
        $removal->admitRetained($notice);
    }

    public function test_denied_recording_never_reaches_consumer_policy(): void
    {
        $this->current = null;
        $policy = $this->createMock(AgentNoticePolicy::class);
        $policy->expects(self::never())->method('allowsRecording');
        $this->expectException(NoticeRejected::class);

        new AgentNoticeAdmission($this->authority(), $policy)->admit($this->notice());
    }

    public function test_removal_selection_refusal_prevents_a_selection_allow(): void
    {
        $this->current = null;
        $policy = $this->createMock(AgentNoticePolicy::class);
        $policy->expects(self::never())->method('allowsRemoval');
        $selection = NoticeRemovalSelection::fromArray([
            'version'   => 1,
            'namespace' => str_repeat('a', 32),
            'targets'   => [['scope' => 'scope', 'producer' => 'producer', 'id' => 'notice', 'position' => 1]]
        ]);
        $this->expectException(NoticeRemovalRejected::class);

        new AgentNoticeRemovalAdmission($this->authority(), $policy)->admit($selection);
    }

    public function test_retained_removal_scope_refusal_remains_a_refusal(): void
    {
        $policy = $this->createStub(AgentNoticePolicy::class);
        $policy->method('allowsRetainedRemoval')->willReturn(false);
        $this->expectException(NoticeRemovalRejected::class);

        new AgentNoticeRemovalAdmission($this->authority(), $policy)->admitRetained($this->notice());
    }

    public function test_read_policy_does_not_receive_selector_as_authority(): void
    {
        $policy = $this->createMock(AgentNoticeReadPolicy::class);
        $access = NoticeAccess::create(['trusted']);
        $policy->expects(self::once())->method('accessFor')->willReturn($access);
        $read = new AgentNoticeReadAdmission(
            $this->fixture->provider,
            $this->fixture->request,
            'read',
            [PermissionName::fromString('NOTICE')],
            $policy
        );

        self::assertSame($access, $read->currentAccess(NoticeSelection::create(['forged'])));
        $read = new AgentNoticeReadAdmission(
            $this->fixture->provider,
            $this->fixture->request,
            'read',
            [PermissionName::fromString('OTHER')],
            $policy
        );
        $this->expectException(PermissionDenied::class);
        $read->currentAccess(NoticeSelection::create([]));
    }

    public function test_publication_returns_exact_scope_grant_and_rechecks_authority(): void
    {
        $scope = $this->createMock(AgentNoticePublicationScope::class);
        $notice = new NoticeView($this->notice(), '2026-11-10T12:00:00Z');
        $grant = NoticePublicationGrant::create('https://notice.example/topic', true);
        $scope->expects(self::once())->method('currentGrant')->with(self::anything(), $notice)->willReturn($grant);
        $policy = new AgentNoticePublicationPolicy($this->authority(), $scope);

        self::assertSame($grant, $policy->currentGrant($notice));
        $this->current = null;
        self::assertNull($policy->currentGrant($notice));
    }

    public function test_operational_failure_keeps_exact_throwable(): void
    {
        $repository = $this->createStub(AgentRepository::class);
        $repository->method('getOperationContract')
            ->willReturn($this->fixture->agentRepository->getOperationContract());
        $failure = new RuntimeException('authority unavailable');
        $repository->method('getById')->willThrowException($failure);
        $authority = new AgentNoticeAuthority(
            $this->binding,
            $repository,
            $this->fixture->permissionRepository,
            $this->clock,
            [PermissionName::fromString('NOTICE')]
        );
        $this->expectExceptionObject($failure);

        $authority->resolve();
    }

    private function authority(?PermissionRepository $permissions = null): AgentNoticeAuthority
    {
        $repository = $this->createStub(AgentRepository::class);
        $repository->method('getOperationContract')
            ->willReturn($this->fixture->agentRepository->getOperationContract());
        $repository->method('getById')->willReturnCallback(fn(): ?Agent => $this->current);

        return new AgentNoticeAuthority(
            $this->binding,
            $repository,
            $permissions ?? $this->fixture->permissionRepository,
            $this->clock,
            [PermissionName::fromString('NOTICE')]
        );
    }

    private function notice(): Notice
    {
        return Notice::fromArray([
            'version'       => 1,
            'id'            => 'notice',
            'producer'      => 'producer',
            'scope'         => 'scope',
            'topic'         => 'topic',
            'type'          => 'changed',
            'occurred_at'   => null,
            'metadata'      => [],
            'relationships' => [
                'subject'    => null,
                'recipients' => [],
                'author'     => null,
                'command'    => null,
                'event'      => null,
                'action'     => null
            ]
        ]);
    }
}
