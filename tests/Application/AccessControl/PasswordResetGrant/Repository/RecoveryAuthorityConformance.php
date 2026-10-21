<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class RecoveryAuthorityConformance extends TestCase
{
    public function test_persisted_authority_survives_claim_outcome_and_restart(): void
    {
        $repository = $this->repository();
        $grant = $this->grant(UserId::generate(), 'first');
        self::assertTrue($repository->add($grant));
        $repository = $this->restart($repository);
        $stored = $repository->getById($grant->getId());
        self::assertNotNull($stored);
        self::assertSame(7, $stored->getAuthority()->getAuthenticationVersion());
        self::assertSame(4, $stored->getAuthority()->getCanonicalEmailRevision());
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $claim = CredentialDeliveryClaimToken::generate();
        $claimed = $stored->claimDelivery($claim, $at, $at->modify('+5 minutes'));
        $other = new RecoveryAuthority(8, 4);
        self::assertFalse($repository->replace($this->withAuthority($stored, $other), $claimed));
        self::assertFalse($repository->replace($stored, $this->withAuthority($claimed, $other)));
        self::assertEquals($stored, $repository->getById($grant->getId()));
        self::assertTrue($repository->replace($stored, $claimed));
        $repository = $this->restart($repository);
        $current = $repository->getByDeliveryId($grant->getDelivery()->getId());
        self::assertTrue($claimed->getDelivery()->sameStateAs($current->getDelivery()));
        self::assertTrue($claimed->getAuthority()->equals($current->getAuthority()));
        self::assertTrue($repository->replace($current, $current->confirmDelivery($claim, $at->modify('+1 minute'))));
        $repository = $this->restart($repository);
        self::assertTrue($repository->getById($grant->getId())->isIssued());
        self::assertTrue($repository->getById($grant->getId())->getAuthority()->equals($grant->getAuthority()));
    }

    public function test_successor_preserves_terminal_predecessor_binding(): void
    {
        $repository = $this->repository();
        $grant = $this->grant(UserId::generate(), 'first');
        $successor = $this->grant($grant->getUserId(), 'next');
        self::assertTrue($repository->add($grant));
        $terminal = $grant->revoke(new DateTimeImmutable('2030-01-01T12:00:00Z'));
        self::assertFalse($repository->replaceWithSuccessor(
            $this->withAuthority($grant, new RecoveryAuthority(7, 5)),
            $terminal,
            $successor
        ));
        self::assertFalse($repository->replaceWithSuccessor(
            $grant,
            $this->withAuthority($terminal, new RecoveryAuthority(7, 5)),
            $successor
        ));
        self::assertTrue($repository->replaceWithSuccessor($grant, $terminal, $successor));
        $repository = $this->restart($repository);
        $storedTerminal = $repository->getById($grant->getId());
        self::assertTrue($storedTerminal->isRevoked());
        self::assertEquals($terminal->getRevokedAt(), $storedTerminal->getRevokedAt());
        self::assertTrue($terminal->getAuthority()->equals($storedTerminal->getAuthority()));
        $storedSuccessor = $repository->getLatestByUserId($grant->getUserId());
        self::assertTrue($successor->getId()->equals($storedSuccessor->getId()));
        self::assertTrue($successor->getAuthority()->equals($storedSuccessor->getAuthority()));
        self::assertFalse($repository->replace($grant, $grant->consume(new DateTimeImmutable('2030-01-01T12:01:00Z'))));
        self::assertFalse($repository->appendAfterTerminal(
            $this->withAuthority($terminal, new RecoveryAuthority(8, 4)),
            $this->grant($grant->getUserId(), 'third')
        ));
    }

    abstract protected function repository(): PasswordResetGrantRepository;

    abstract protected function restart(PasswordResetGrantRepository $repository): PasswordResetGrantRepository;

    private function grant(UserId $id, string $credential): PasswordResetGrant
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');

        return PasswordResetGrant::issue(
            $id,
            PasswordResetCredential::fromString($credential),
            $at,
            $at->modify('+1 hour'),
            EmailAddress::fromString('recovery@example.test'),
            'fixture:'.$credential,
            new RecoveryAuthority(7, 4)
        );
    }

    private function withAuthority(PasswordResetGrant $grant, RecoveryAuthority $authority): PasswordResetGrant
    {
        return ExtensiblePasswordResetGrant::reconstitute(
            $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            $grant->getDelivery(),
            $authority,
            $grant->getConsumedAt(),
            $grant->getRevokedAt(),
            $grant->getRevision()
        );
    }
}
