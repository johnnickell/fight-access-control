<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
abstract class RecoveryAuthorityConformance extends TestCase
{
    public function test_persisted_authority_survives_claim_outcome_and_restart(): void
    {
        $user = $this->user();
        $repository = $this->repository($user);
        $grant = $this->grant($user, 'first');
        $this->transaction(static function () use ($repository, $grant): void {
            self::assertTrue($repository->add($grant));
        });
        $repository = $this->restart($repository);
        $stored = $repository->getById($grant->getId());
        self::assertNotNull($stored);
        self::assertSame(7, $stored->getAuthority()->getAuthenticationVersion());
        self::assertSame(4, $stored->getAuthority()->getCanonicalEmailRevision());
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $claim = CredentialDeliveryClaimToken::generate();
        $claimed = $stored->claimDelivery($claim, $at, $at->modify('+5 minutes'));
        $other = new RecoveryAuthority(8, 4);
        $this->transaction(function () use ($repository, $stored, $claimed, $other, $grant): void {
            self::assertFalse($repository->replace($this->withAuthority($stored, $other), $claimed));
            self::assertFalse($repository->replace($stored, $this->withAuthority($claimed, $other)));
            self::assertEquals($stored, $repository->getById($grant->getId()));
            self::assertTrue($repository->replace($stored, $claimed));
        });
        $repository = $this->restart($repository);
        $current = $repository->getByDeliveryId($grant->getDelivery()->getId());
        self::assertTrue($claimed->getDelivery()->sameStateAs($current->getDelivery()));
        self::assertTrue($claimed->getAuthority()->equals($current->getAuthority()));
        $this->transaction(static function () use ($repository, $current, $claim, $at): void {
            self::assertTrue($repository->replace(
                $current,
                $current->confirmDelivery($claim, $at->modify('+1 minute'))
            ));
        });
        $repository = $this->restart($repository);
        self::assertTrue($repository->getById($grant->getId())->isIssued());
        self::assertTrue($repository->getById($grant->getId())->getAuthority()->equals($grant->getAuthority()));
    }

    public function test_successor_preserves_terminal_predecessor_binding(): void
    {
        $user = $this->user();
        $repository = $this->repository($user);
        $grant = $this->grant($user, 'first');
        $successor = $this->grant($user, 'next');
        $terminal = $grant->revoke(new DateTimeImmutable('2030-01-01T12:00:00Z'));
        $this->transaction(function () use ($repository, $grant, $terminal, $successor): void {
            self::assertTrue($repository->add($grant));
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
        });
        $repository = $this->restart($repository);
        $storedTerminal = $repository->getById($grant->getId());
        self::assertTrue($storedTerminal->isRevoked());
        self::assertEquals($terminal->getRevokedAt(), $storedTerminal->getRevokedAt());
        self::assertTrue($terminal->getAuthority()->equals($storedTerminal->getAuthority()));
        $storedSuccessor = $repository->getLatestByUserId($grant->getUserId());
        self::assertTrue($successor->getId()->equals($storedSuccessor->getId()));
        self::assertTrue($successor->getAuthority()->equals($storedSuccessor->getAuthority()));
        $this->transaction(function () use ($repository, $grant, $terminal, $user): void {
            self::assertFalse($repository->replace(
                $grant,
                $grant->consume(new DateTimeImmutable('2030-01-01T12:01:00Z'))
            ));
            self::assertFalse($repository->appendAfterTerminal(
                $this->withAuthority($terminal, new RecoveryAuthority(8, 4)),
                $this->grant($user, 'third')
            ));
        });
    }

    public function test_admission_requires_persisted_user_authority_before_and_after_restart(): void
    {
        $user = $this->user();
        $repository = $this->repository($user);
        $grant = $this->grant($user, 'first');
        $orphan = $this->grant($this->user(), 'orphan');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->transaction(function () use ($repository, $grant, $orphan): void {
                self::assertFalse($repository->add($orphan));
                self::assertFalse($repository->add($this->withAuthority($grant, new RecoveryAuthority(8, 4))));
                self::assertFalse($repository->add($this->withAuthority($grant, new RecoveryAuthority(7, 5))));
                self::assertNull($repository->getById($orphan->getId()));
                self::assertNull($repository->getById($grant->getId()));
            });
            $repository = $this->restart($repository);
        }

        $this->transaction(static function () use ($repository, $grant): void {
            self::assertTrue($repository->add($grant));
        });
        $repository = $this->restart($repository);
        $this->assertStoredGrant($grant, $repository->getById($grant->getId()));
    }

    public function test_grant_write_rolls_back_with_the_shared_transaction(): void
    {
        $user = $this->user();
        $repository = $this->repository($user);
        $grant = $this->grant($user, 'first');
        $failure = new RuntimeException('Conformance transaction interruption.');
        try {
            $this->transaction(static function () use ($repository, $grant, $failure): void {
                self::assertTrue($repository->add($grant));

                throw $failure;
            });
            self::fail('The interrupted transaction must throw.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $repository = $this->restart($repository);
        self::assertNull($repository->getById($grant->getId()));
        $this->transaction(static function () use ($repository, $grant): void {
            self::assertTrue($repository->add($grant));
        });
        $repository = $this->restart($repository);
        $this->assertStoredGrant($grant, $repository->getById($grant->getId()));
    }

    /**
     * Persists the exact User in isolated storage and returns its bound grant repository
     *
     * User setup must commit through the same connection/Unit of Work as subsequent grant writes.
     * Do not synthesize User state inside grant writes or disable authority admission.
     */
    abstract protected function repository(User $user): PasswordResetGrantRepository;

    /**
     * Reopens both persisted User and grant state without issuing replacement authority
     */
    abstract protected function restart(PasswordResetGrantRepository $repository): PasswordResetGrantRepository;

    /**
     * Commits the operation or rolls it back and rethrows through the bound Unit of Work
     *
     * @param callable(): void $operation
     */
    abstract protected function transaction(callable $operation): void;

    private function assertStoredGrant(PasswordResetGrant $expected, ?PasswordResetGrant $actual): void
    {
        self::assertNotNull($actual);
        self::assertTrue($expected->getId()->equals($actual->getId()));
        self::assertTrue($expected->getUserId()->equals($actual->getUserId()));
        self::assertSame($expected->getCredentialHash(), $actual->getCredentialHash());
        self::assertEquals($expected->getExpiresAt(), $actual->getExpiresAt());
        self::assertTrue($expected->getAuthority()->equals($actual->getAuthority()));
        self::assertTrue($expected->getDelivery()->getId()->equals($actual->getDelivery()->getId()));
        self::assertTrue($expected->getDelivery()->sameStateAs($actual->getDelivery()));
        self::assertEquals($expected->getConsumedAt(), $actual->getConsumedAt());
        self::assertEquals($expected->getRevokedAt(), $actual->getRevokedAt());
        self::assertSame($expected->getRevision(), $actual->getRevision());
    }

    private function user(): User
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $user = User::invite(UserId::generate(), EmailAddress::fromString('initial@example.test'), $at);
        $hash = PasswordHash::fromString(password_hash('conformance-password', PASSWORD_DEFAULT));
        $user->activate($hash, $at);
        foreach (['one', 'two', 'three', 'recovery'] as $name) {
            $user->requestEmailChange(EmailAddress::fromString($name.'@example.test'), $at);
            $user->confirmEmailChange($at);
        }

        $user->changePassword($hash, $at);
        $user->changePassword($hash, $at);

        return $user;
    }

    private function grant(User $user, string $credential): PasswordResetGrant
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');

        return PasswordResetGrant::issue(
            $user->getId(),
            PasswordResetCredential::fromString($credential),
            $at,
            $at->modify('+1 hour'),
            $user->getEmail(),
            'fixture:'.$credential,
            $user->getRecoveryAuthority()
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
