<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentOperationContract
 *
 * Captures persisted cohort settings and the local composition's qualified capabilities, never caller authority.
 */
final readonly class AgentOperationContract
{
    public const array REQUIRED_CAPABILITIES = [
        'operation-storage-v1',
        'atomic-authority-v1',
        'bound-cipher-v1',
        'ordered-sink-v1',
        'material-maintenance-v1'
    ];

    /**
     * Constructs AgentOperationContract
     *
     * Versions and generation come from authoritative storage. Capabilities are the intersection of persisted
     * qualification and the actual local composition, not request input or an unconditional ready boolean.
     * Preserve unknown versions for rejection; never default absent storage to this package's current versions.
     *
     * @phpstan-param list<string> $capabilities
     */
    public function __construct(
        private int $storageVersion,
        private int $canonicalVersion,
        private int $destinationVersion,
        private int $generation,
        private array $capabilities
    ) {
    }

    /**
     * Validates this worker's complete contract before mutation, admission or material access
     */
    public function assertCompatible(): void
    {
        if (
            $this->storageVersion !== 1 || $this->destinationVersion !== 1 || $this->generation < 1
            || $this->canonicalVersion !== AgentOperationCanonicalization::VERSION
            || array_diff(self::REQUIRED_CAPABILITIES, $this->capabilities) !== []
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }
    }

    /**
     * Validates that two repository participants observe the same compatible cohort generation
     */
    public function assertSameCohort(self $other): void
    {
        $this->assertCompatible();
        $other->assertCompatible();
        if ($this->generation !== $other->generation) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAVAILABLE);
        }
    }

    /**
     * Creates cohort-bound authority evidence so a switch or switch-back cannot acknowledge an old admission
     */
    public function bindAuthority(AgentDeliveryAuthority $authority): AgentDeliveryAuthority
    {
        $this->assertCompatible();

        return new AgentDeliveryAuthority(
            hash('sha256', $this->generation.':'.$authority->getEpoch()),
            $authority->getExpiresAt()
        );
    }
}
