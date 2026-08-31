<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a failed Agent credential lifecycle operation without retaining secret material.
 */
final readonly class AgentCredentialLifecycleFailed implements Event
{
    /**
     * Creates safe Agent credential lifecycle failure evidence.
     */
    public function __construct(private string $actorId, private string $errorMessage)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'error_message'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static((string) $data['actor_id'], (string) $data['error_message']);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['actor_id' => $this->actorId, 'error_message' => $this->errorMessage];
    }

    /**
     * Returns the safe consumer-supplied lifecycle actor identifier.
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the generic safe failure message without operation inputs.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
