<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivationGrant::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(User::class)]
final class OfflineCredentialExpiryRegressionTest extends TestCase
{
    #[DataProvider('families')]
    public function test_offline_expiry_accepts_reclaimed_history_without_recording_another_failure(
        string $family
    ): void {
        $issuedAt = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $expiresAt = new DateTimeImmutable('2030-01-01T01:00:00Z');
        $userId = UserId::generate();
        $email = EmailAddress::fromString('expiry@example.test');
        [$grant, $repository] = match ($family) {
            'activation' => [ActivationGrant::issue(
                $userId,
                ActivationCredential::fromString('fixture'), $issuedAt, $expiresAt, $email, 'fixture:encrypted'
            ), new InMemoryActivationGrantRepository()],
            'password_reset' => [PasswordResetGrant::issue(
                $userId,
                PasswordResetCredential::fromString('fixture'), $issuedAt, $expiresAt, $email, 'fixture:encrypted'
            ), new InMemoryPasswordResetGrants()],
            'email_change' => [EmailChangeGrant::issue(
                $userId,
                EmailChangeCredential::fromString('fixture'), $issuedAt, $expiresAt, $email, 'fixture:encrypted', 1
            ), new InMemoryEmailChangeGrantRepository()],
            default => throw new LogicException('Unknown fixture family.'),
        };
        self::assertTrue($repository->add($grant));
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $grant->claimDelivery($token, $issuedAt, $issuedAt->modify('+30 seconds'));
        self::assertTrue($repository->replace($grant, $claimed));
        $failed = $claimed->failDelivery(
            $token,
            $issuedAt->modify('+1 second'),
            CredentialDeliveryFailure::RETRYABLE_PROVIDER
        );
        self::assertTrue($repository->replace($claimed, $failed));
        $reclaimed = $failed->claimDelivery(
            CredentialDeliveryClaimToken::generate(),
            $issuedAt->modify('+2 minutes'),
            $issuedAt->modify('+3 minutes')
        );
        self::assertTrue($repository->replace($failed, $reclaimed));
        self::assertSame([], $repository->findDue($expiresAt, 50));
        $expired = $reclaimed->expireDeliveryAt($expiresAt);

        self::assertTrue(
            $repository->replace($reclaimed, $expired),
            'Expiry must not replay the previous failed attempt.'
        );
        self::assertSame($expired, $repository->getLatestByUserId($userId));
        self::assertFalse($expired->getDelivery()->hasRecoverableMaterial());
        self::assertNull($expired->getDelivery()->getClaimToken());
        self::assertNull($expired->getDelivery()->getClaimedAt());
        self::assertNull($expired->getDelivery()->getLeaseUntil());
        self::assertSame(2, $expired->getDelivery()->getAttemptCount());
        self::assertEquals($reclaimed->getDelivery()->getLastOutcomeAt(), $expired->getDelivery()->getLastOutcomeAt());
        self::assertSame($reclaimed->getDelivery()->getLastFailure(), $expired->getDelivery()->getLastFailure());
    }

    public function test_a_disabled_user_can_release_an_expired_reservation_without_reactivation(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $user->requestEmailChange(EmailAddress::fromString('new@example.test'), $at);
        $user->disable($at);

        $authority = $user->getAuthenticationVersion();

        $user->expireEmailChange($at->modify('+1 hour'));

        self::assertSame(UserState::DISABLED, $user->getState());
        self::assertSame($authority, $user->getAuthenticationVersion());
        self::assertSame('old@example.test', $user->getEmail()->canonical());
        self::assertNull($user->getPendingEmailChange());
        self::assertSame(2, $user->getEmailChangeReservationRevision());
    }

    /** @return list<array{string}> */
    public static function families(): array
    {
        return [['activation'], ['password_reset'], ['email_change']];
    }
}
