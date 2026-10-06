<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Tool\McpTool;
use Fight\Common\Application\Mcp\Tool\McpToolAvailability;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Exception\DomainException;
use ReflectionMethod;
use Throwable;

/**
 * Class AgentToolPermissionCatalog
 *
 * Captures static requirements from one complete Common registry, never a filtered request catalog.
 */
final readonly class AgentToolPermissionCatalog
{
    /** @var array<string, array{definition: McpToolInfo, permissions: non-empty-list<PermissionName>}> */
    private array $requirements;

    /**
     * Constructs AgentToolPermissionCatalog
     *
     * Common owns canonical identity and duplicate rejection. This construction-only predicate enumerates
     * its complete registry; it must never be used as request authorization.
     */
    public function __construct(McpToolRegistry $registry)
    {
        $definitions = $registry->available(new class implements McpToolAvailability {
            /**
             * @inheritDoc
             */
            public function isAvailable(McpToolInfo $tool): bool
            {
                return true;
            }
        });
        $requirements = [];
        foreach ($definitions as $definition) {
            $tool = $registry->find($definition->name());
            assert($tool instanceof McpTool);
            $attributes = new ReflectionMethod($tool, 'handle')->getAttributes(RequiresAgentPermission::class);
            if ($attributes === []) {
                throw new DomainException('Every protected Tool must declare at least one Agent Permission.');
            }

            try {
                $permissions = [];
                foreach ($attributes as $attribute) {
                    $permissions[] = $attribute->newInstance()->getPermissionName();
                }
            } catch (Throwable $exception) {
                throw new DomainException('A protected Tool has invalid Agent Permission metadata.', 0, $exception);
            }

            $requirements[$definition->name()] = ['definition' => $definition, 'permissions' => $permissions];
        }

        $this->requirements = $requirements;
    }

    /**
     * Returns requirements only for the exact immutable definition registered by Common
     *
     * Foreign or absent definitions deny rather than borrowing authority from a matching name.
     * No reflection or principal state participates in this lookup.
     *
     * @return non-empty-list<PermissionName>|null
     */
    public function requirementsFor(McpToolInfo $definition): ?array
    {
        $requirement = $this->requirements[$definition->name()] ?? null;
        if ($requirement === null || $requirement['definition'] !== $definition) {
            return null;
        }

        return $requirement['permissions'];
    }
}
