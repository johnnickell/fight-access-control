<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\Common\Domain\Messaging\Query\Query;
use SensitiveParameter;

/**
 * Class CountAgentDeliveryKeyReferences
 *
 * Requests a diagnostic global count, never authority to destroy a key.
 */
final readonly class CountAgentDeliveryKeyReferences implements Query
{
    /**
     * Constructs CountAgentDeliveryKeyReferences
     */
    public function __construct(private AgentDeliveryKeyVersion $version)
    {
    }

    /**
     * Creates an accounting request without accepting key paths or absent data
     *
     * @throws AgentOperationRejectedException When required data is absent or invalid
     */
    public static function fromArray(#[SensitiveParameter] array $data): static
    {
        if (!isset($data['key_version']) || !is_string($data['key_version'])) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }

        return new static(new AgentDeliveryKeyVersion($data['key_version']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['key_version' => $this->version->toString()];
    }

    /**
     * Returns the opaque delivery wrapping version
     */
    public function getVersion(): AgentDeliveryKeyVersion
    {
        return $this->version;
    }
}
