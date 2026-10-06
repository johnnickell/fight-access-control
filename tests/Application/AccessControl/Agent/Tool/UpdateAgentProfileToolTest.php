<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Tool;

use Fight\AccessControl\Application\AccessControl\Agent\Tool\UpdateAgentProfileTool;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UpdateAgentProfileTool::class)]
final class UpdateAgentProfileToolTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'trimmed empty' => [" \t\n "];
        yield 'too long' => [str_repeat('a', 121)];
    }

    public function test_synchronous_dispatch_uses_normalized_name_and_principal_as_target_and_typed_initiator(): void
    {
        $env = new ProfileToolEnvironment();
        $commands = $this->createMock(SynchronousCommandBus::class);
        $commands->expects(self::never())->method('dispatch');
        $completed = false;
        $commands->expects(self::once())->method('execute')->willReturnCallback(
            static function (UpdateAgent $command) use ($env, &$completed): void {
                self::assertTrue($env->original->getId()->equals($command->getAgentId()));
                self::assertTrue($env->original->getId()->equals($command->getInitiator()->getId()));
                self::assertSame(AuthenticatedPrincipalType::AGENT, $command->getInitiator()->getType());
                self::assertSame('Worker é', $command->getName());
                $completed = true;
            }
        );
        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::never())->method('report');
        $output = $env->update($commands)->handle(new ApplicationData([
            'name'      => " \tWorker é\n",
            'agent_id'  => AgentId::generate()->toString(),
            'initiator' => 'caller cannot choose provenance'
        ]), $progress);
        self::assertTrue($completed);
        $expected = ['agent_id' => $env->original->getId()->toString(), 'name' => 'Worker é'];
        self::assertSame($expected, $output->structuredContent()->properties());
        self::assertSame($expected, json_decode($output->text(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([['type' => 'text', 'text' => $output->text()]], $output->contentItems());
        self::assertSame([], $env->storage->events->events());
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_domain_name_never_dispatches(string $name): void
    {
        $env = new ProfileToolEnvironment();
        $commands = $this->createMock(SynchronousCommandBus::class);
        $commands->expects(self::never())->method('execute');
        $this->expectException(AgentNameException::class);
        $env->update($commands)->handle(
            new ApplicationData(['name' => $name]),
            $this->createStub(McpProgressReporter::class)
        );
    }

    public function test_dispatch_failure_propagates_without_an_acknowledgement_or_compensating_read(): void
    {
        $env = new ProfileToolEnvironment();
        $failure = new RuntimeException('Private fixture publication detail');
        $commands = $this->createMock(SynchronousCommandBus::class);
        $commands->expects(self::once())->method('execute')->willThrowException($failure);
        $this->expectExceptionObject($failure);
        $env->update($commands)->handle(
            new ApplicationData(['name' => 'Renamed']),
            $this->createStub(McpProgressReporter::class)
        );
    }
}
