<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;

final class ExtensibleActivationDelivery extends ActivationDelivery
{
    public static function reconstitute(
        ActivationDeliveryId $id,
        UserId $userId,
        EmailAddress $email,
        ?string $ciphertext,
        DateTimeImmutable $expiresAt,
        ActivationDeliveryStatus $status
    ): self {
        return new self($id, $userId, $email, $ciphertext, $expiresAt, $status);
    }
}
