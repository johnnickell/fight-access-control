<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Mcp\Tool\Agent;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\Attribute\RequiresAgentPermission;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpTool;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolOutput;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use SensitiveParameter;

/**
 * Class UpdateAgentProfileTool
 *
 * Acknowledges successful synchronous dispatch, not a subsequent read or a distinct changed/no-op result.
 */
final readonly class UpdateAgentProfileTool implements McpTool
{
    /**
     * Constructs UpdateAgentProfileTool
     */
    public function __construct(
        private CurrentAgentPrincipalProvider $provider,
        #[SensitiveParameter] private SignedAgentRequest $request,
        private string $correlationId,
        private SynchronousCommandBus $synchronousCommandBus
    ) {
    }

    /**
     * @inheritDoc
     */
    #[McpToolInfo(
        'agent.profile.update',
        'Update the authenticated Agent name',
        [
            'type'                 => 'object',
            'properties'           => ['name' => [
                'type'        => 'string',
                'description' => 'AgentName: fixed Unicode edges + NUL; nonempty UTF-8, at most 120 code points.'
            ]],
            'required'             => ['name'],
            'additionalProperties' => false
        ],
        [
            'type'                 => 'object',
            'properties'           => ['agent_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required'             => ['agent_id', 'name'],
            'additionalProperties' => false
        ]
    )]
    #[RequiresAgentPermission(AgentProfilePermissions::UPDATE)]
    public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
    {
        $principal = $this->provider->resolve($this->request, $this->correlationId);
        $id = $principal->getAgentId();
        /** @var string $inputName */
        $inputName = $input->get('name');
        $name = AgentName::fromString($inputName);
        $this->synchronousCommandBus->execute(new UpdateAgent(new AgentUpdateInitiator($id), $id, $name->toString()));

        return McpToolOutput::structured(['agent_id' => $id->toString(), 'name' => $name->toString()]);
    }
}
