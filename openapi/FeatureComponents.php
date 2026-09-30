<?php

declare(strict_types=1);

namespace Fight\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Non-autoloaded Feature provisioning payload metadata, not an endpoint or activation receipt.
 */
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
