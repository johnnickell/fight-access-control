<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Disposable consumer root for generated Agent discovery contract integration tests.
 */
#[OA\OpenApi(openapi: '3.1.0')]
#[OA\Info(title: 'Agent discovery contract consumer', version: 'test')]
#[OA\Schema(schema: 'Consumer.AgentRecovery', type: 'object')]
final class AgentDeliveryConsumerDocument
{
}
