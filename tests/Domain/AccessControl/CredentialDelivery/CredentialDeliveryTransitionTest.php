<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Exception\CredentialDeliveryTransitionException;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialDelivery::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(EmailChangeGrant::class)]
final class CredentialDeliveryTransitionTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function invalidOutcomes(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach (['unclaimed', 'wrong token', 'before claim', 'expired lease', 'reclaimed', 'terminal'] as $state) {
                foreach (['confirmDelivery', 'failDelivery', 'failDeliveryPermanently'] as $outcome) {
                    yield $purpose.' '.$state.' '.$outcome => [$purpose, $state, $outcome];
                }
            }
        }
    }

    #[DataProvider('invalidOutcomes')]
    public function test_every_aggregate_rejects_outcomes_without_the_exact_live_claim(
        string $purpose,
        string $state,
        string $outcome
    ): void {
        $at = new DateTimeImmutable('2026-09-30T12:00:00Z');
        $grant = $this->grant($purpose, $at);
        $token = CredentialDeliveryClaimToken::generate();
        $leaseUntil = $at->modify('+5 minutes');
        $candidate = $grant;
        if ($state !== 'unclaimed') {
            $candidate = $grant->claimDelivery($token, $at, $leaseUntil);
        }

        $occurredAt = $at;
        if ($state === 'wrong token') {
            $token = CredentialDeliveryClaimToken::generate();
        } elseif ($state === 'before claim') {
            $occurredAt = $at->modify('-1 second');
        } elseif ($state === 'expired lease') {
            $occurredAt = $leaseUntil;
        } elseif ($state === 'reclaimed') {
            $candidate = $candidate->claimDelivery(
                CredentialDeliveryClaimToken::generate(),
                $leaseUntil,
                $leaseUntil->modify('+5 minutes')
            );
            $occurredAt = $leaseUntil;
        } elseif ($state === 'terminal') {
            $candidate = $candidate->confirmDelivery($token, $at);
        }

        $before = $candidate->getDelivery();
        try {
            if ($outcome === 'failDelivery') {
                $candidate->failDelivery($token, $occurredAt, CredentialDeliveryFailure::RETRYABLE_PROVIDER);
            } elseif ($outcome === 'confirmDelivery') {
                $candidate->confirmDelivery($token, $occurredAt);
            } else {
                $candidate->failDeliveryPermanently($token, $occurredAt);
            }

            self::fail('An outcome without the exact live claim must reject.');
        } catch (CredentialDeliveryTransitionException) {
            self::assertSame($before, $candidate->getDelivery());
            self::assertSame($grant->getId(), $candidate->getId());
            self::assertSame(CredentialDeliveryStatus::PENDING, $grant->getDelivery()->getStatus());
        }
    }

    private function grant(
        string $purpose,
        DateTimeImmutable $at
    ): ActivationGrant|PasswordResetGrant|EmailChangeGrant {
        $user = UserId::generate();
        $email = EmailAddress::fromString('recipient@example.test');
        $expiry = $at->modify('+1 hour');

        return match ($purpose) {
            'activation' => ActivationGrant::issue(
                $user,
                ActivationCredential::fromString('test-credential'),
                $at,
                $expiry,
                $email,
                'encrypted-material'
            ),
            'password_reset' => PasswordResetGrant::issue(
                $user,
                PasswordResetCredential::fromString('test-credential'),
                $at,
                $expiry,
                $email,
                'encrypted-material'
            ),
            'email_change' => EmailChangeGrant::issue(
                $user,
                EmailChangeCredential::fromString('test-credential'),
                $at,
                $expiry,
                $email,
                'encrypted-material',
                1
            ),
            default => throw new LogicException('Unknown grant purpose.')
        };
    }
}
