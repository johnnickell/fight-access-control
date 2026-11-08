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
    type: ['object', 'array'],
    maxProperties: 0,
    maxItems: 0,
    properties: []
)]
#[OA\Schema(
    schema: 'Fight.AccessControl.FeaturePreparationIssue',
    description: 'Safe configuration diagnostic, not an infrastructure error.',
    type: 'object',
    required: ['name', 'problem'],
    properties: [
        new OA\Property(property: 'name', ref: '#/components/schemas/Fight.AccessControl.FeatureName'),
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
#[OA\Schema(schema: 'Fight.AccessControl.FeatureName', type: 'string', minLength: 1, maxLength: 128, pattern: '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?![\\s\\S])')]
#[OA\Schema(schema: 'Fight.AccessControl.CreateFeature', required: ['name', 'permission_id'], properties: [new OA\Property(property: 'name', ref: '#/components/schemas/Fight.AccessControl.FeatureName'), new OA\Property(property: 'permission_id', type: 'string', format: 'uuid')])]
#[OA\Schema(schema: 'Fight.AccessControl.RemoveFeature', required: ['feature_id', 'expected_revision'], properties: [new OA\Property(property: 'feature_id', type: 'string', format: 'uuid'), new OA\Property(property: 'expected_revision', type: 'integer', minimum: 1)])]
#[OA\Schema(schema: 'Fight.AccessControl.SetFeaturePermission', required: ['feature_id', 'permission_id', 'expected_revision'], properties: [new OA\Property(property: 'feature_id', type: 'string', format: 'uuid'), new OA\Property(property: 'permission_id', type: 'string', format: 'uuid'), new OA\Property(property: 'expected_revision', type: 'integer', minimum: 1)])]
#[OA\Schema(schema: 'Fight.AccessControl.SetFeatureStatus', required: ['feature_id', 'status', 'expected_revision'], properties: [new OA\Property(property: 'feature_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status', type: 'string', enum: ['off', 'preview', 'on']), new OA\Property(property: 'expected_revision', type: 'integer', minimum: 1)])]
#[OA\Schema(schema: 'Fight.AccessControl.GetFeatureById', required: ['feature_id'], properties: [new OA\Property(property: 'feature_id', type: 'string', format: 'uuid')])]
#[OA\Schema(schema: 'Fight.AccessControl.ListFeatures', ref: '#/components/schemas/Fight.AccessControl.PaginationRequest')]
#[OA\Schema(schema: 'Fight.AccessControl.Feature', oneOf: [new OA\Schema(properties: [new OA\Property(property: 'permission_name', type: ['null']), new OA\Property(property: 'binding_missing', type: 'boolean', enum: [true])]), new OA\Schema(properties: [new OA\Property(property: 'permission_name', type: 'string'), new OA\Property(property: 'binding_missing', type: 'boolean', enum: [false])])], required: ['feature_id', 'name', 'status', 'permission_id', 'permission_name', 'binding_missing', 'revision'], properties: [new OA\Property(property: 'feature_id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', ref: '#/components/schemas/Fight.AccessControl.FeatureName'), new OA\Property(property: 'status', type: 'string', enum: ['off', 'preview', 'on']), new OA\Property(property: 'permission_id', type: 'string', format: 'uuid'), new OA\Property(property: 'permission_name', type: ['string', 'null'], pattern: '^[A-Z]+(?:_[A-Z]+)*(?![\\s\\S])'), new OA\Property(property: 'binding_missing', type: 'boolean'), new OA\Property(property: 'revision', type: 'integer', minimum: 1)])]
#[OA\Schema(schema: 'Fight.AccessControl.FeatureCollection', required: ['page', 'per_page', 'total_pages', 'total_records', 'records'], properties: [new OA\Property(property: 'page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total_pages', type: 'integer'), new OA\Property(property: 'total_records', type: 'integer'), new OA\Property(property: 'records', type: 'array', items: new OA\Items(ref: '#/components/schemas/Fight.AccessControl.Feature'))])]
#[OA\Schema(schema: 'Fight.AccessControl.JSend.Success.Feature', required: ['status', 'data'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['success']), new OA\Property(property: 'data', ref: '#/components/schemas/Fight.AccessControl.Feature')])]
#[OA\Schema(schema: 'Fight.AccessControl.JSend.Success.FeatureCollection', required: ['status', 'data'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['success']), new OA\Property(property: 'data', ref: '#/components/schemas/Fight.AccessControl.FeatureCollection')])]
final class FeatureComponents
{
}
