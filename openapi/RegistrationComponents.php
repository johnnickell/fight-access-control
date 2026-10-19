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
final class RegistrationComponents
{
}
