<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class InvitePendingUser
 */
final readonly class InvitePendingUser implements Command
{
    /**
     * Constructs InvitePendingUser
     */
    public function __construct(
        private UserId|AgentId|SystemId|AnonymousId $actorId,
        private UserId $userId,
        private EmailAddress $email
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_kind', 'actor_id', 'user_id', 'email'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        $kind = AuditActorKind::tryFrom($data['actor_kind']) ?? throw new DomainException('Unsupported actor kind.');

        return new static(
            $kind->idFromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            EmailAddress::fromString($data['email'])
        );
    }

    /**
     * Returns the canonical invitation payload
     *
     * @phpstan-return array{actor_kind: string, actor_id: string, user_id: string, email: string}
     */
    public function toArray(): array
    {
        return [
            'actor_kind' => AuditActorKind::forId($this->actorId)->value,
            'actor_id'   => $this->actorId->toString(),
            'user_id'    => $this->userId->toString(),
            'email'      => $this->email->toString()
        ];
    }

    /**
     * Retrieves the inviting actor ID
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId
    {
        return $this->actorId;
    }

    /**
     * Retrieves the pending user ID
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Retrieves the invited email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
