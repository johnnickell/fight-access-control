<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Activates an invited identity and establishes its initial credential.
 */
final readonly class ActivateInvitedAccount implements Command
{
    /**
     * Constructs the account-activation command.
     */
    public function __construct(
        private UserId $userId,
        private ActivationCredential $activationCredential,
        private PasswordHash $passwordHash
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'activation_credential', 'password_hash'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            ActivationCredential::fromString((string) $data['activation_credential']),
            PasswordHash::fromString((string) $data['password_hash'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'               => $this->userId->toString(),
            'activation_credential' => $this->activationCredential->toString(),
            'password_hash'         => $this->passwordHash->toString(),
        ];
    }

    /**
     * Returns the invited user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the one-time activation credential.
     */
    public function getActivationCredential(): ActivationCredential
    {
        return $this->activationCredential;
    }

    /**
     * Returns the selected initial password hash.
     */
    public function getPasswordHash(): PasswordHash
    {
        return $this->passwordHash;
    }
}
