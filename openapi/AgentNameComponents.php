<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Fight.AccessControl.AgentUpdateInitiator',
    description: 'Typed name-update provenance; this identity grants no authorization.',
    required: ['type', 'id'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['user', 'agent']),
        new OA\Property(property: 'id', type: 'string', format: 'uuid')
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.UpdateAgent',
    description: 'Consumer-authorized name-only intent. Void synchronous dispatch; no handler result or changed field.',
    required: ['initiator', 'agent_id', 'name'],
    properties: [
        new OA\Property(property: 'initiator', ref: '#/components/schemas/Fight.AccessControl.AgentUpdateInitiator'),
        new OA\Property(property: 'agent_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', description: 'AgentName trims input and requires 1–120 characters after trimming.', type: 'string')
    ],
    type: 'object'
)]
final class AgentNameComponents
{
}
