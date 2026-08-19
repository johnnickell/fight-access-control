<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

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
        private string $activationCredential,
        private string $initialPassword
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'activation_credential', 'initial_password'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            (string) $data['activation_credential'],
            (string) $data['initial_password']
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'               => $this->userId->toString(),
            'activation_credential' => $this->activationCredential,
            'initial_password'      => $this->initialPassword,
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
    public function getActivationCredential(): string
    {
        return $this->activationCredential;
    }

    /**
     * Returns the selected initial password for hashing at the application boundary.
     */
    public function getInitialPassword(): string
    {
        return $this->initialPassword;
    }
}
