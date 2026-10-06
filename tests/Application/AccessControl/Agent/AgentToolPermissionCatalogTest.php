<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpTool;
use Fight\Common\Application\Mcp\Tool\McpToolAvailability;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolOutput;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\ProtectedTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentToolPermissionCatalog::class)]
#[CoversClass(RequiresAgentPermission::class)]
final class AgentToolPermissionCatalogTest extends TestCase
{
    public function test_catalog_retains_complete_canonical_metadata_and_never_executes_tools(): void
    {
        $tool = new ProtectedTool(static function (): never {
            throw new RuntimeException('Catalog construction must not execute the Tool.');
        });
        $registry = new McpToolRegistry([$tool]);
        $catalog = new AgentToolPermissionCatalog($registry);
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        $requirements = $catalog->requirementsFor($definition);
        self::assertNotNull($requirements);
        self::assertSame(['VIEW_AGENTS', 'UPDATE_AGENTS'], array_map(
            static fn($permission): string => $permission->toString(),
            $requirements
        ));
        self::assertSame($requirements, $catalog->requirementsFor($definition));
        self::assertSame(0, $tool->calls);
    }

    public function test_foreign_definition_cannot_borrow_permissions_even_with_identical_canonical_metadata(): void
    {
        $registry = new McpToolRegistry([new ProtectedTool(static fn(): null => null)]);
        $catalog = new AgentToolPermissionCatalog($registry);
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        self::assertNull($catalog->requirementsFor(clone $definition));
        self::assertNull($catalog->requirementsFor(new McpToolInfo(
            'unknown',
            'Unknown Tool',
            ['type' => 'object'],
            []
        )));
        self::assertNull(new AgentToolPermissionCatalog(new McpToolRegistry([]))->requirementsFor($definition));
    }

    public function test_common_rejects_duplicate_canonical_identity_before_a_catalog_can_be_constructed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tool canonical names must be unique.');

        new AgentToolPermissionCatalog(new McpToolRegistry([
            new ProtectedTool(static fn(): null => null),
            new ProtectedTool(static fn(): null => null)
        ]));
    }

    public function test_unprotected_tool_works_separately_but_rejects_a_mixed_protected_composition(): void
    {
        $tool = new class implements McpTool {
            #[McpToolInfo('public', 'Public fixture', ['type' => 'object'], ['type' => 'object'])]
            public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
            {
                return McpToolOutput::structured(['public' => true]);
            }
        };
        $public = new McpToolRegistry([$tool]);
        self::assertSame($tool, $public->find('public'));
        $available = new class implements McpToolAvailability {
            public function isAvailable(McpToolInfo $tool): bool
            {
                return true;
            }
        };
        self::assertSame('complete', new McpToolInvoker($public, $available)
            ->invoke('public', (object) [])->toArray()['resultType']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Every protected Tool must declare at least one Agent Permission.');

        new AgentToolPermissionCatalog(new McpToolRegistry([
            new ProtectedTool(static fn(): null => null),
            $tool
        ]));
    }

    public function test_native_requirement_type_failure_rejects_composition(): void
    {
        $tool = require __DIR__.'/Fixture/MalformedAgentPermission.php.fixture';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A protected Tool has invalid Agent Permission metadata.');

        new AgentToolPermissionCatalog(new McpToolRegistry([$tool]));
    }

    public function test_common_rejects_contradictory_tool_metadata_before_catalog_construction(): void
    {
        $tool = new class implements McpTool {
            #[McpToolInfo('contradictory', 'Not an interactive Tool', ['type' => 'object'], [], true)]
            #[RequiresAgentPermission('VIEW_AGENTS')]
            public function handle(ApplicationData $input, McpProgressReporter $progress): never
            {
                throw new RuntimeException('Contradictory Tool must never execute.');
            }
        };
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Interactive Tools must explicitly require form elicitation in metadata.');

        new AgentToolPermissionCatalog(new McpToolRegistry([$tool]));
    }

    public function test_invalid_requirement_after_a_valid_tool_rejects_the_whole_catalog(): void
    {
        $invalid = new class implements McpTool {
            #[McpToolInfo('z.invalid', 'Invalid fixture', ['type' => 'object'], [])]
            #[RequiresAgentPermission('VIEW_AGENTS')]
            #[RequiresAgentPermission('not-canonical')]
            public function handle(ApplicationData $input, McpProgressReporter $progress): never
            {
                throw new RuntimeException('Invalid Tool must never execute.');
            }
        };
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A protected Tool has invalid Agent Permission metadata.');

        new AgentToolPermissionCatalog(new McpToolRegistry([
            new ProtectedTool(static fn(): null => null),
            $invalid
        ]));
    }
}
