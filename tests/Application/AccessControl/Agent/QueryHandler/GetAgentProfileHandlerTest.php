<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GetAgentProfileHandler::class)]
final class GetAgentProfileHandlerTest extends TestCase
{
    public function test_each_request_reads_the_current_name_without_authority_projection_or_writes(): void
    {
        $agent = $this->agent();
        $renamed = $agent->rename(AgentName::fromString('Renamed worker'), new DateTimeImmutable('2026-01-03'));
        $agents = $this->readOnlyRepository();
        $agents->expects(self::exactly(2))->method('getById')->with($agent->getId())->willReturn($agent, $renamed);
        $handler = new GetAgentProfileHandler($agents);
        $message = QueryMessage::create(new GetAgentProfile($agent->getId()));

        self::assertInstanceOf(QueryHandler::class, $handler);
        self::assertSame(GetAgentProfile::class, GetAgentProfileHandler::queryRegistration());
        $first = $handler->handle($message);
        $second = $handler->handle($message);

        self::assertInstanceOf(AgentProfileView::class, $first);
        self::assertInstanceOf(AgentProfileView::class, $second);
        self::assertSame(
            ['agent_id' => '018f0000-0000-7000-8000-000000000070', 'name' => 'Deployment worker'],
            $first->toArray()
        );
        self::assertSame(
            ['agent_id' => '018f0000-0000-7000-8000-000000000070', 'name' => 'Renamed worker'],
            $second->toArray()
        );
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('encrypted-profile-fixture', serialize($first));
        self::assertSame('Deployment worker', $agent->getName()->toString());
        self::assertSame('Deployment worker', $first->getName()->toString());
    }

    public function test_missing_and_revoked_targets_are_equally_unavailable_even_after_a_successful_read(): void
    {
        $agent = $this->agent();
        $revoked = $agent->revoke(new DateTimeImmutable('2026-01-03'));
        $agents = $this->readOnlyRepository();
        $agents->expects(self::exactly(3))
            ->method('getById')
            ->with($agent->getId())
            ->willReturn($agent, $revoked, null);
        $handler = new GetAgentProfileHandler($agents);
        $message = QueryMessage::create(new GetAgentProfile($agent->getId()));

        self::assertInstanceOf(AgentProfileView::class, $handler->handle($message));
        self::assertNull($handler->handle($message));
        self::assertNull($handler->handle($message));
    }

    public function test_repository_failure_propagates_instead_of_becoming_absence_or_a_cached_result(): void
    {
        $agent = $this->agent();
        $failure = new RuntimeException('Storage unavailable');
        $agents = $this->readOnlyRepository();
        $agents->expects(self::exactly(2))->method('getById')->with($agent->getId())->willReturnCallback(
            static function () use (&$agent, $failure): Agent {
                if ($agent === null) {
                    throw $failure;
                }

                $current = $agent;
                $agent = null;

                return $current;
            }
        );
        $handler = new GetAgentProfileHandler($agents);
        $message = QueryMessage::create(new GetAgentProfile($agent->getId()));
        self::assertInstanceOf(AgentProfileView::class, $handler->handle($message));

        try {
            $handler->handle($message);
            self::fail('A failed lookup must not return a cached profile.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }
    }

    private function readOnlyRepository(): AgentRepository&MockObject
    {
        $agents = $this->createMock(AgentRepository::class);
        foreach (
            [
                'add',
                'rename',
                'replace',
                'replacePermissionAssignments',
                'validatePermissionAssignments',
                'getOperationContract',
                'getByCredentialId',
                'getAll',
                'hasPermissionAssignment'
            ] as $method
        ) {
            $agents->expects(self::never())->method($method);
        }

        return $agents;
    }

    private function agent(): Agent
    {
        // Assigned but deliberately unresolved Permission: the profile is not an administrative projection.
        return Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000070'),
            AgentName::fromString('Deployment worker'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000170'),
            'encrypted-profile-fixture',
            new DateTimeImmutable('2026-01-01')
        )->grantPermission(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000270'),
            new DateTimeImmutable('2026-01-02')
        );
    }
}
