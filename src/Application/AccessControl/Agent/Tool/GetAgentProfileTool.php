<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Tool;

use Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpTool;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolOutput;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Common\Domain\Exception\LookupException;
use SensitiveParameter;

/**
 * Class GetAgentProfileTool
 *
 * Uses the same request-scoped provider as AgentToolAvailability; direct calls do not enforce Permissions.
 */
final readonly class GetAgentProfileTool implements McpTool
{
    /**
     * Constructs GetAgentProfileTool
     */
    public function __construct(
        private CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private SignedAgentRequest $request,
        private string $correlationId,
        private QueryBus $queries
    ) {
    }

    /**
     * @inheritDoc
     */
    #[McpToolInfo(
        'agent.profile.read',
        'Read the authenticated Agent profile',
        ['type' => 'object', 'additionalProperties' => false],
        [
            'type'                 => 'object',
            'properties'           => ['agent_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required'             => ['agent_id', 'name'],
            'additionalProperties' => false
        ]
    )]
    #[RequiresAgentPermission(AgentProfilePermissions::READ)]
    public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
    {
        $principal = $this->provider->resolve($this->request, $this->correlationId);
        /** @var AgentProfileView|null $profile */
        $profile = $this->queries->fetch(new GetAgentProfile($principal->getAgentId()));
        if ($profile === null) {
            throw new LookupException('The Agent profile is unavailable.');
        }

        return McpToolOutput::structured($profile->toArray());
    }
}
