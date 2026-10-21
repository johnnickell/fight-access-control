<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetDelivery;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetGrant;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(RecoveryAuthority::class)]
final class InMemoryRecoveryAuthorityConformanceTest extends RecoveryAuthorityConformance
{
    protected function repository(): PasswordResetGrantRepository
    {
        return new InMemoryPasswordResetGrants();
    }

    protected function restart(PasswordResetGrantRepository $repository): PasswordResetGrantRepository
    {
        assert($repository instanceof InMemoryPasswordResetGrants);
        $state = new InMemoryPasswordResetGrantState();
        foreach ($repository->all() as $grant) {
            $state->grants[] = ExtensiblePasswordResetGrant::reconstitute(
                $grant->getId(),
                $grant->getUserId(),
                $grant->getCredentialHash(),
                $grant->getExpiresAt(),
                ExtensiblePasswordResetDelivery::fromStoredState($grant->getDelivery()),
                new RecoveryAuthority(
                    $grant->getAuthority()->getAuthenticationVersion(),
                    $grant->getAuthority()->getCanonicalEmailRevision()
                ),
                $grant->getConsumedAt(),
                $grant->getRevokedAt(),
                $grant->getRevision()
            );
        }

        return new InMemoryPasswordResetGrants(state: $state);
    }
}
