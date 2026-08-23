<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

final class ExtensiblePasswordResetDelivery extends PasswordResetDelivery
{
    public static function reconstitute(
        PasswordResetDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        ?string $ciphertext,
        DateTimeImmutable $expiresAt
    ): self {
        return new self($id, $userId, $email, $ciphertext, $expiresAt);
    }
}
