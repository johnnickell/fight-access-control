<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Tool;

use Fight\AccessControl\Application\AccessControl\Agent\Tool\GetAgentProfileTool;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Common\Domain\Exception\LookupException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GetAgentProfileTool::class)]
final class GetAgentProfileToolTest extends TestCase
{
    public function test_each_read_fetches_only_the_principal_target_and_returns_fresh_minimal_json(): void
    {
        $env = new ProfileToolEnvironment();
        $principal = $env->provider->resolve($env->request, $env->correlationId);
        $transactions = $env->storage->transaction->transactions;
        $queries = $this->createMock(QueryBus::class);
        $queries->expects(self::never())->method('dispatch');
        $queries->expects(self::exactly(2))->method('fetch')->with(self::callback(
            static fn(GetAgentProfile $query): bool => $query->getAgentId()->equals($principal->getAgentId())
        ))->willReturnOnConsecutiveCalls(
            new AgentProfileView($principal->getAgentId(), AgentName::fromString('First')),
            new AgentProfileView($principal->getAgentId(), AgentName::fromString('Later'))
        );
        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::never())->method('report');
        $tool = $env->read($queries);
        foreach (['First', 'Later'] as $name) {
            // Even bypassed caller data cannot select a different target; Common rejects it at invocation.
            $output = $tool->handle(new ApplicationData(['agent_id' => AgentId::generate()->toString()]), $progress);
            $expected = ['agent_id' => $principal->getAgentId()->toString(), 'name' => $name];
            self::assertSame($expected, $output->structuredContent()->properties());
            self::assertSame($expected, json_decode($output->text(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame([['type' => 'text', 'text' => $output->text()]], $output->contentItems());
        }

        self::assertSame($transactions, $env->storage->transaction->transactions);
        self::assertSame(1, $env->nonces->consumptionCalls());
        self::assertSame(0, $env->storage->agents->nameWrites);
        self::assertSame([], $env->storage->events->events());
    }

    public function test_unavailable_profile_has_no_success_output(): void
    {
        $env = new ProfileToolEnvironment();
        $queries = $this->createMock(QueryBus::class);
        $queries->expects(self::once())->method('fetch')->willReturn(null);
        $this->expectException(LookupException::class);
        $this->expectExceptionMessage('The Agent profile is unavailable.');
        $env->read($queries)->handle(new ApplicationData([]), $this->createStub(McpProgressReporter::class));
    }

    public function test_storage_failure_is_not_cached_or_converted_to_absence(): void
    {
        $env = new ProfileToolEnvironment();
        $failure = new RuntimeException('Private fixture storage detail');
        $queries = $this->createMock(QueryBus::class);
        $queries->expects(self::once())->method('fetch')->willThrowException($failure);
        $this->expectExceptionObject($failure);
        $env->read($queries)->handle(new ApplicationData([]), $this->createStub(McpProgressReporter::class));
    }
}
