<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Non-autoloaded safe Agent discovery payloads, never transport or authorization policy.
 */
#[OA\Schema(
    schema: 'Fight.AccessControl.ListDueAgentDeliveries',
    required: ['namespace', 'caller_type', 'caller_id', 'destination_id', 'destination_revision', 'limit'],
    properties: [
        new OA\Property(property: 'namespace', type: 'string', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_type', type: 'string', minLength: 1, maxLength: 32, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_id', type: 'string', minLength: 1, maxLength: 128, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'destination_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'destination_revision', type: 'integer', minimum: 1),
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 100, default: 50)
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentOperationKey',
    required: ['namespace', 'caller_type', 'caller_id', 'operation_id'],
    properties: [
        new OA\Property(property: 'namespace', type: 'string', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_type', type: 'string', minLength: 1, maxLength: 32, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_id', type: 'string', minLength: 1, maxLength: 128, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'operation_id', type: 'string', format: 'uuid')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentIssuance',
    description: 'Original issuance only; not delivery, activation or permission to use a credential.',
    required: ['namespace', 'caller_type', 'caller_id', 'operation_id', 'delivery_id', 'agent_id', 'credential_id',
        'credential_revision', 'destination_id', 'destination_revision', 'destination_write_version', 'issued_at'],
    properties: [
        new OA\Property(property: 'namespace', type: 'string', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_type', type: 'string', minLength: 1, maxLength: 32, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'caller_id', type: 'string', minLength: 1, maxLength: 128, pattern: '^[A-Za-z0-9_.:-]+$'),
        new OA\Property(property: 'operation_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'agent_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'credential_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'credential_revision', type: 'integer', minimum: 0),
        new OA\Property(property: 'destination_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'destination_revision', type: 'integer', minimum: 1),
        new OA\Property(property: 'destination_write_version', type: 'integer', minimum: 1),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date-time', description: 'Canonical timestamp with six fractional digits and numeric offset.')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentOperation.Confirmed',
    required: ['key', 'issuance_outcome', 'canonical_version', 'issuance', 'delivery_disposition', 'credential_disposition'],
    properties: [
        new OA\Property(property: 'key', ref: '#/components/schemas/Fight.AccessControl.AgentOperationKey'),
        new OA\Property(property: 'issuance_outcome', type: 'string', enum: ['confirmed']),
        new OA\Property(property: 'canonical_version', type: 'integer', enum: [1, 2]),
        new OA\Property(property: 'issuance', ref: '#/components/schemas/Fight.AccessControl.AgentIssuance'),
        new OA\Property(property: 'delivery_disposition', type: 'string', enum: ['pending', 'delivered', 'retired', 'expired', 'retryable', 'terminal']),
        new OA\Property(property: 'credential_disposition', type: 'string', enum: ['current', 'superseded', 'revoked'])
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentOperation.Indeterminate',
    required: ['key', 'issuance_outcome', 'canonical_version', 'issuance', 'delivery_disposition', 'credential_disposition'],
    properties: [
        new OA\Property(property: 'key', ref: '#/components/schemas/Fight.AccessControl.AgentOperationKey'),
        new OA\Property(property: 'issuance_outcome', type: 'string', enum: ['indeterminate']),
        new OA\Property(property: 'canonical_version', type: ['null']),
        new OA\Property(property: 'issuance', type: ['null']),
        new OA\Property(property: 'delivery_disposition', type: ['null']),
        new OA\Property(property: 'credential_disposition', type: ['null'])
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.AgentOperation',
    oneOf: [
        new OA\Schema(ref: '#/components/schemas/Fight.AccessControl.AgentOperation.Confirmed'),
        new OA\Schema(ref: '#/components/schemas/Fight.AccessControl.AgentOperation.Indeterminate')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.DueAgentDeliveries',
    description: 'Bounded confirmed AgentOperationView values, not a paginated ResultSet or admission capability.',
    type: 'array',
    maxItems: 100,
    items: new OA\Items(ref: '#/components/schemas/Fight.AccessControl.AgentOperation.Confirmed')
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.JSend.Success.DueAgentDeliveries',
    required: ['status', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success']),
        new OA\Property(property: 'data', ref: '#/components/schemas/Fight.AccessControl.DueAgentDeliveries')
    ]
)]
final class AgentDeliveryComponents
{
}
