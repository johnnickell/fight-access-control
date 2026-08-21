<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Requests generic password-recovery delivery for an email address.
 */
final readonly class RequestPasswordReset implements Command
{
    /**
     * Constructs a password-reset request.
     */
    public function __construct(private EmailAddress $email)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('email', $data)) {
            throw new DomainException('Missing required key "email" in data array');
        }

        return new static(EmailAddress::fromString((string) $data['email']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['email' => $this->email->toString()];
    }

    /**
     * Returns the requested email address.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
