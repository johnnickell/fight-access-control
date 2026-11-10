<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
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
    public function __construct(private UserId|AgentId|SystemId|AnonymousId|null $actorId, private string $errorMessage)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (
            !isset($data['error_message']) || !is_string($data['error_message'])
            || !array_key_exists('actor_kind', $data) || !array_key_exists('actor_id', $data)
        ) {
            throw new DomainException('Missing or invalid Agent lifecycle failure field.');
        }

        if ($data['actor_kind'] === null && $data['actor_id'] === null) {
            return new static(null, $data['error_message']);
        }

        if (!is_string($data['actor_kind']) || !is_string($data['actor_id'])) {
            throw new DomainException('Agent lifecycle failure provenance must have both kind and identity.');
        }

        $kind = AuditActorKind::tryFrom($data['actor_kind']) ?? throw new DomainException('Unsupported actor kind.');

        return new static($kind->idFromString($data['actor_id']), $data['error_message']);
    }

    /**
     * Returns typed failure evidence supplied by the owning publisher
     *
     * @phpstan-return array{actor_kind: ?string, actor_id: ?string, error_message: string}
     */
    public function toArray(): array
    {
        return [
            'actor_kind'    => $this->actorId === null ? null : AuditActorKind::forId($this->actorId)->value,
            'actor_id'      => $this->actorId?->toString(),
            'error_message' => $this->errorMessage
        ];
    }

    /**
     * Returns the safe consumer-supplied lifecycle actor identifier
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId|null
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
