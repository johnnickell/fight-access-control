<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Non-autoloaded safe maintenance payloads, never physical key-retirement or delivery authority.
 */
#[OA\Schema(
    schema: 'Fight.AccessControl.ListAgentDeliveryMaintenance',
    required: ['namespace', 'caller_type', 'caller_id', 'destination_id', 'destination_revision', 'work', 'batch_size', 'cleanup_grace_seconds', 'after'],
    properties: [
        new OA\Property(property: 'namespace', type: 'string', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_type', type: 'string', minLength: 1, maxLength: 32, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_id', type: 'string', minLength: 1, maxLength: 128, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'destination_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'destination_revision', type: 'integer', minimum: 1),
        new OA\Property(property: 'work', type: 'string', enum: ['material', 'cleanup'], default: 'material'),
        new OA\Property(property: 'batch_size', type: 'integer', minimum: 1, maximum: 100, default: 50),
        new OA\Property(property: 'cleanup_grace_seconds', type: 'integer', minimum: 1, maximum: 604800, default: 86400),
        new OA\Property(property: 'after', type: ['string', 'null'], format: 'uuid', description: 'Exclusive delivery-ID cursor. Restart at null after the final page.')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentDeliveryMaintenance',
    description: 'One bounded keyset page of original safe operation views. No implicit unbounded scan or material access.',
    type: 'array',
    maxItems: 100,
    items: new OA\Items(ref: '#/components/schemas/Fight.AccessControl.AgentOperation.Confirmed')
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.CountAgentDeliveryKeyReferences',
    required: ['key_version'],
    properties: [
        new OA\Property(property: 'key_version', type: 'string', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_.-]+$')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentDeliveryKeyReferences',
    description: 'Authorized global diagnostic count only. Zero never grants permission to destroy a key.',
    type: 'integer',
    minimum: 0
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentMaintenanceResult',
    type: 'string',
    enum: ['rewrapped', 'expired', 'terminal', 'cleaned', 'unchanged', 'retryable', 'rejected', 'unavailable', 'indeterminate']
)]
final class AgentMaintenanceComponents
{
}
