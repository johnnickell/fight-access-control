<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Attempts a login using an email identity and submitted secret.
 */
final readonly class Login implements Command
{
    /**
     * Creates a login command.
     */
    public function __construct(
        private EmailAddress $email,
        private string $secret
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['email', 'secret'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(EmailAddress::fromString((string) $data['email']), (string) $data['secret']);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'email'  => $this->email->canonical(),
            'secret' => $this->secret,
        ];
    }

    /**
     * Returns the submitted login identity.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the submitted secret for immediate verification only.
     */
    public function getSecret(): string
    {
        return $this->secret;
    }
}
