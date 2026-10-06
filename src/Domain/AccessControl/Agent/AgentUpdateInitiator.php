<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\ValueObject;

/**
 * Class AgentUpdateInitiator
 *
 * Identifies name-update provenance, never permission to update an Agent.
 */
final readonly class AgentUpdateInitiator extends ValueObject
{
    /**
     * Constructs AgentUpdateInitiator
     */
    public function __construct(private UserId|AgentId $id)
    {
    }

    /**
     * Creates typed provenance from its type-qualified identity
     */
    public static function fromString(string $value): self
    {
        $parts = explode(':', $value, 2);
        if (count($parts) !== 2) {
            throw new DomainException('An Agent update initiator requires a type-qualified identity.');
        }

        return self::fromArray(['type' => $parts[0], 'id' => $parts[1]]);
    }

    /**
     * Creates typed provenance from its canonical representation
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['type', 'id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        $type = AuthenticatedPrincipalType::from((string) $data['type']);
        $id = match ($type) {
            AuthenticatedPrincipalType::USER => UserId::fromString((string) $data['id']),
            AuthenticatedPrincipalType::AGENT => AgentId::fromString((string) $data['id'])
        };

        return new self($id);
    }

    /**
     * Returns the initiating identity
     */
    public function getId(): UserId|AgentId
    {
        return $this->id;
    }

    /**
     * Returns the identity type derived from its typed identifier
     */
    public function getType(): AuthenticatedPrincipalType
    {
        if ($this->id instanceof UserId) {
            return AuthenticatedPrincipalType::USER;
        }

        return AuthenticatedPrincipalType::AGENT;
    }

    /**
     * Returns the type-qualified identity for value equality
     */
    public function toString(): string
    {
        return $this->getType()->value.':'.$this->id->toString();
    }

    /**
     * Returns canonical secret-free provenance
     *
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->getType()->value, 'id' => $this->id->toString()];
    }
}
