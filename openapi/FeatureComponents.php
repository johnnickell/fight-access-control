<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Non-autoloaded Feature provisioning payload metadata, not an endpoint or activation receipt.
 */
#[OA\Schema(
    schema: 'Fight.AccessControl.ValidateFeaturePreparation',
    description: 'Empty query payload. Consumer-composed discovery supplies the complete candidate inventory.',
    type: 'object',
    properties: []
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.FeaturePreparationIssue',
    description: 'Safe configuration diagnostic, not an infrastructure error.',
    type: 'object',
    required: ['name', 'problem'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'problem', type: 'string', enum: ['missing_feature', 'broken_binding', 'invalid_definition'])
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.FeaturePreparationResult',
    description: 'Read-only configuration preparation; success does not authorize activation or runtime use.',
    type: 'object',
    required: ['prepared', 'issues'],
    properties: [
        new OA\Property(property: 'prepared', type: 'boolean'),
        new OA\Property(
            property: 'issues',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Fight.AccessControl.FeaturePreparationIssue')
        )
    ]
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.ProvisionFeatures',
    description: 'Provision complete candidate-code references through consumer-composed discovery. Completion is not activation readiness. Default configuration is validated only when creation is needed.',
    type: 'object',
    required: ['default_permission_name'],
    properties: [
        new OA\Property(
            property: 'default_permission_name',
            description: 'Raw consumer configuration or null. A missing Feature requires a resolvable canonical Permission name; unused configuration is not validated.',
            type: ['string', 'null']
        )
    ]
)]
final class FeatureComponents
{
}
