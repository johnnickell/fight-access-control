<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Fight.AccessControl.RegisterUser',
    description: 'Consumer-admitted pending registration. No authenticated actor, Role choice or credentials. Consumers own public exposure and response mapping.',
    required: ['user_id', 'email'],
    properties: [
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email')
    ],
    type: 'object',
    additionalProperties: false
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.UserRegistered',
    description: 'Confirmed pending User/default Roles/activation delivery/audit commit, not mail arrival or activation. No authenticated actor is asserted.',
    required: ['user_id', 'activation_delivery_id', 'email', 'issued_at'],
    properties: [
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'activation_delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date-time')
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.RecoverPendingRegistration',
    description: 'Administrator-only pending registration correction or same-email reissue at/after expiry. Actor identity is not authority; exact target authorization is mandatory inside the transaction. Expected delivery ID fences the generation.',
    required: ['actor_id', 'user_id', 'expected_activation_delivery_id', 'email'],
    properties: [
        new OA\Property(property: 'actor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expected_activation_delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email')
    ],
    type: 'object',
    additionalProperties: false
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.PendingRegistrationRecovered',
    description: 'Confirmed atomic pending-User recovery and registration-origin successor delivery, not mail arrival or activation. No credential material.',
    required: ['actor_id', 'user_id', 'previous_activation_delivery_id', 'activation_delivery_id', 'email'],
    properties: [
        new OA\Property(property: 'actor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'previous_activation_delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'activation_delivery_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email')
    ],
    type: 'object',
    additionalProperties: false
)]
final class RegistrationComponents
{
}
