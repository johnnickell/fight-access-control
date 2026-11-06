<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class AgentCredentialLifecycleFailed
 *
 * Records a failed Agent credential lifecycle operation without retaining secret material.
 */
final readonly class AgentCredentialLifecycleFailed implements Event
{
    /**
     * Constructs AgentCredentialLifecycleFailed
     *
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
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException('Missing or invalid Agent lifecycle failure field.');
            }
        }

        return new static($data['actor_id'], $data['error_message']);
    }

    /**
     * Returns typed failure evidence supplied by the owning publisher
     *
     * @phpstan-return array{actor_id: string, error_message: string}
     */
    public function toArray(): array
    {
        return ['actor_id' => $this->actorId, 'error_message' => $this->errorMessage];
    }

    /**
     * Returns the safe consumer-supplied lifecycle actor identifier
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the generic safe failure message without operation inputs
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
