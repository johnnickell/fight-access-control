<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\UpdateAgentProfileTool;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(UpdateAgentProfileTool::class)]
final class UpdateAgentProfileToolTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'trimmed empty' => [" \t\n "];
        yield 'unicode empty' => ["\u{00A0}\u{3000}"];
        yield 'too long' => [str_repeat('é', 121)];
        yield 'invalid encoding' => ["\xFF"];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedInput(): iterable
    {
        yield 'missing' => [[]];
        foreach ([null, false, 42, 1.5, [], new stdClass()] as $value) {
            yield get_debug_type($value) => [['name' => $value]];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizedNames(): iterable
    {
        yield 'mixed edges' => ["\0\t\u{00A0}Deploy / é\u{3000}\r\n", 'Deploy / é'];
        yield 'internal whitespace and case' => [" De\tploy\u{00A0}A  B ", "De\tploy\u{00A0}A  B"];
        yield 'internal nul' => [" A\0B ", "A\0B"];
        yield '120 code points' => ["\u{3000}".str_repeat('é', 120)."\u{00A0}", str_repeat('é', 120)];
        yield 'zero width and bom are names' => [" \u{200B}\u{FEFF} ", "\u{200B}\u{FEFF}"];
        yield 'decomposed remains decomposed' => [" e\u{0301} ", "e\u{0301}"];
        yield 'composed remains composed' => [' é ', 'é'];
    }

    #[DataProvider('normalizedNames')]
    public function test_synchronous_dispatch_uses_normalized_name_and_principal_as_target_and_typed_initiator(
        string $input,
        string $expectedName
    ): void {
        $env = new ProfileToolEnvironment();
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(self::never())->method('dispatch');
        $completed = false;
        $synchronousCommandBus->expects(self::once())->method('execute')->willReturnCallback(
            static function (UpdateAgent $command) use ($env, &$completed, $expectedName): void {
                self::assertTrue($env->original->getId()->equals($command->getAgentId()));
                self::assertTrue($env->original->getId()->equals($command->getInitiator()->getId()));
                self::assertSame(AuthenticatedPrincipalType::AGENT, $command->getInitiator()->getType());
                self::assertSame($expectedName, $command->getName());
                $completed = true;
            }
        );
        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::never())->method('report');
        $output = $env->update($synchronousCommandBus)->handle(new ApplicationData([
            'name'      => $input,
            'agent_id'  => AgentId::generate()->toString(),
            'initiator' => 'caller cannot choose provenance'
        ]), $progress);
        self::assertTrue($completed);
        $expected = ['agent_id' => $env->original->getId()->toString(), 'name' => $expectedName];
        self::assertSame($expected, $output->structuredContent()->properties());
        self::assertSame($expected, json_decode($output->text(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([['type' => 'text', 'text' => $output->text()]], $output->contentItems());
        self::assertSame([], $env->storage->eventDispatcher->events());
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_domain_name_never_dispatches(string $name): void
    {
        $env = new ProfileToolEnvironment();
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(self::never())->method('execute');
        $this->expectException(AgentNameException::class);
        $env->update($synchronousCommandBus)->handle(
            new ApplicationData(['name' => $name]),
            $this->createStub(McpProgressReporter::class)
        );
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('malformedInput')]
    public function test_dynamic_application_data_rejects_non_string_name_before_dispatch(array $input): void
    {
        $env = new ProfileToolEnvironment();
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(self::never())->method('execute');
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('The Agent name is invalid.');
        $env->update($synchronousCommandBus)->handle(
            new ApplicationData($input),
            $this->createStub(McpProgressReporter::class)
        );
    }

    public function test_dispatch_failure_propagates_without_an_acknowledgement_or_compensating_read(): void
    {
        $env = new ProfileToolEnvironment();
        $failure = new RuntimeException('Private fixture publication detail');
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(self::once())->method('execute')->willThrowException($failure);
        $this->expectExceptionObject($failure);
        $env->update($synchronousCommandBus)->handle(
            new ApplicationData(['name' => 'Renamed']),
            $this->createStub(McpProgressReporter::class)
        );
    }
}
