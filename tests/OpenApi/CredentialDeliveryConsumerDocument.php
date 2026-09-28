<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Disposable consumer root for credential-delivery value contract integration tests.
 */
#[OA\OpenApi(openapi: '3.1.0')]
#[OA\Info(title: 'Credential delivery contract consumer', version: 'test')]
#[OA\Schema(
    schema: 'Consumer.CredentialDelivery',
    type: 'object',
    properties: [
        new OA\Property(property: 'invitation', ref: '#/components/schemas/Fight.AccessControl.InvitationDeliveryStatus'),
        new OA\Property(property: 'delivery', ref: '#/components/schemas/Fight.AccessControl.CredentialDeliveryStatus'),
        new OA\Property(property: 'due', ref: '#/components/schemas/Fight.AccessControl.DueCredentialDeliveries')
    ]
)]
final class CredentialDeliveryConsumerDocument
{
}
