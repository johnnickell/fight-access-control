<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

// Non-autoloaded credential-delivery value contracts, including worker-facing payloads.
#[OA\Schema(
    schema: 'Fight.AccessControl.DeliverPasswordReset',
    required: ['actor_id', 'user_id', 'password_reset_delivery_id'],
    properties: [
        new OA\Property(property: 'actor_id', type: 'string'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'password_reset_delivery_id', type: 'string', format: 'uuid')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.FindCredentialDeliveryStatus',
    required: ['purpose', 'delivery_id'],
    properties: [
        new OA\Property(property: 'purpose', type: 'string', enum: ['activation', 'password_reset', 'email_change']),
        new OA\Property(property: 'delivery_id', type: 'string', format: 'uuid', minLength: 1)
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.FindDueCredentialDeliveries',
    required: ['at', 'limit'],
    properties: [
        new OA\Property(property: 'at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'limit', type: 'integer', minimum: 1)
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.CredentialDeliveryStatus',
    required: [
        'purpose', 'delivery_id', 'user_id', 'revision', 'status', 'due_at', 'expires_at',
        'attempt_count', 'last_attempt_at', 'last_outcome_at', 'last_failure'
    ],
    properties: [
        new OA\Property(property: 'purpose', type: 'string', enum: ['activation', 'password_reset', 'email_change']),
        new OA\Property(property: 'delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'revision', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'claimed', 'retry_pending', 'delivered', 'permanent_failure', 'expired', 'invalidated']),
        new OA\Property(property: 'due_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'attempt_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'last_attempt_at', type: ['string', 'null'], format: 'date-time'),
        new OA\Property(property: 'last_outcome_at', type: ['string', 'null'], format: 'date-time'),
        new OA\Property(property: 'last_failure', type: ['string', 'null'], enum: ['retryable_provider', 'unexpected_provider', 'permanent_provider', null])
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.DueCredentialDelivery',
    required: ['purpose', 'delivery_id', 'user_id', 'due_at', 'revision', 'status'],
    properties: [
        new OA\Property(property: 'purpose', type: 'string', enum: ['activation', 'password_reset', 'email_change']),
        new OA\Property(property: 'delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'due_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'revision', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'claimed', 'retry_pending', 'delivered', 'permanent_failure', 'expired', 'invalidated'])
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.DueCredentialDeliveries',
    type: 'array',
    items: new OA\Items(ref: '#/components/schemas/Fight.AccessControl.DueCredentialDelivery')
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.JSend.Success.CredentialDeliveryStatus',
    required: ['status', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success']),
        new OA\Property(property: 'data', ref: '#/components/schemas/Fight.AccessControl.CredentialDeliveryStatus')
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.JSend.Success.DueCredentialDeliveries',
    required: ['status', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success']),
        new OA\Property(property: 'data', ref: '#/components/schemas/Fight.AccessControl.DueCredentialDeliveries')
    ]
)]
final class CredentialDeliveryComponents
{
}
