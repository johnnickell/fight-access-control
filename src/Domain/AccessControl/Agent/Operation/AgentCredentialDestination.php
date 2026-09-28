<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\Common\Domain\Type\Arrayable;
use SensitiveParameter;
use Throwable;

/**
 * Class AgentCredentialDestination
 *
 * Binds delivery to a registered slot revision, never a caller-supplied URL or path.
 */
final readonly class AgentCredentialDestination implements Arrayable
{
    /**
     * Constructs AgentCredentialDestination
     */
    public function __construct(private AgentDestinationId $id, private int $revision)
    {
        if ($revision < 1) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Creates a registered destination reference without accepting a path or granting authority
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        try {
            if (
                !isset($data['destination_id'], $data['destination_revision'])
                || !is_string($data['destination_id'])
                || !is_int($data['destination_revision'])
            ) {
                throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
            }

            return new self(AgentDestinationId::fromString($data['destination_id']), $data['destination_revision']);
        } catch (Throwable) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }
    }

    /**
     * Returns the canonical safe destination binding
     *
     * @return array{destination_id: string, destination_revision: int}
     */
    public function toArray(): array
    {
        return ['destination_id' => $this->id->toString(), 'destination_revision' => $this->revision];
    }

    /**
     * Returns the permanent slot identity
     */
    public function getId(): AgentDestinationId
    {
        return $this->id;
    }

    /**
     * Returns the immutable ownership binding revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }
}
