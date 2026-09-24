<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Exception\CredentialDeliveryTransitionException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivationDelivery::class)]
#[CoversClass(ActivationDeliveryException::class)]
#[CoversClass(CredentialDelivery::class)]
#[CoversClass(CredentialDeliveryClaimToken::class)]
#[CoversClass(CredentialDeliveryFailure::class)]
#[CoversClass(CredentialDeliveryStatus::class)]
#[CoversClass(EncryptedCredentialMaterial::class)]
#[CoversClass(CredentialDeliveryTransitionException::class)]
final class ActivationDeliveryLifecycleTest extends TestCase
{
    public function test_it_exposes_safe_state_and_narrow_encrypted_material(): void
    {
        $delivery = $this->delivery();

        self::assertNotSame('', $delivery->getId()->toString());
        self::assertNotSame('', $delivery->getUserId()->toString());
        self::assertSame('alice@example.test', $delivery->getEmail()->canonical());
        self::assertSame('ciphertext', $delivery->getEncryptedMaterial()?->reveal());
        self::assertEquals($this->at('13:00:00'), $delivery->getExpiresAt());
        self::assertEquals($this->at('12:00:00'), $delivery->getDueAt());
        self::assertEquals($this->at('12:00:00'), $delivery->getNextAttemptAt());
        self::assertSame(CredentialDeliveryStatus::PENDING, $delivery->getStatus());
        self::assertNull($delivery->getClaimToken());
        self::assertNull($delivery->getClaimedAt());
        self::assertNull($delivery->getLeaseUntil());
        self::assertSame(0, $delivery->getAttemptCount());
        self::assertNull($delivery->getLastAttemptAt());
        self::assertNull($delivery->getLastOutcomeAt());
        self::assertNull($delivery->getLastFailure());
        self::assertTrue($delivery->hasRecoverableMaterial());
        self::assertTrue($delivery->isRetryable());
        self::assertFalse($delivery->isDueAt($this->at('11:59:59')));
        self::assertTrue($delivery->isDueAt($this->at('12:00:00')));
        self::assertTrue($delivery->sameStateAs($delivery));
        self::assertFalse($delivery->sameStateAs($delivery->invalidate()));
    }

    public function test_a_malformed_live_claim_cannot_expose_absent_material(): void
    {
        $token = CredentialDeliveryClaimToken::generate();
        $claimedAt = $this->at('12:00:00');
        $delivery = ExtensibleActivationDelivery::claimedWithoutMaterial(
            $token,
            $claimedAt,
            $this->at('12:05:00')
        );

        $this->expectException(CredentialDeliveryTransitionException::class);
        $delivery->materialForClaim($token, $claimedAt);
    }

    public function test_it_claims_due_work_and_materializes_only_for_the_matching_live_claim(): void
    {
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $this->delivery()->claim($token, $this->at('12:00:00'), $this->at('12:05:00'));

        self::assertSame(CredentialDeliveryStatus::CLAIMED, $claimed->getStatus());
        self::assertTrue($token->equals($claimed->getClaimToken()));
        self::assertEquals($this->at('12:00:00'), $claimed->getClaimedAt());
        self::assertEquals($this->at('12:05:00'), $claimed->getLeaseUntil());
        self::assertEquals($this->at('12:05:00'), $claimed->getNextAttemptAt());
        self::assertSame(1, $claimed->getAttemptCount());
        self::assertEquals($this->at('12:00:00'), $claimed->getLastAttemptAt());
        self::assertFalse($claimed->isRetryable());
        self::assertSame('ciphertext', $claimed->materialForClaim($token, $this->at('12:04:59'))->reveal());

        $this->expectException(CredentialDeliveryTransitionException::class);
        $claimed->materialForClaim(CredentialDeliveryClaimToken::generate(), $this->at('12:04:59'));
    }

    public function test_it_rejects_material_access_and_outcomes_before_the_claim_begins(): void
    {
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $this->delivery()->claim($token, $this->at('12:00:00'), $this->at('12:05:00'));
        $beforeClaim = $this->at('11:59:59');
        $actions = [
            fn(): EncryptedCredentialMaterial => $claimed->materialForClaim($token, $beforeClaim),
            fn(): ActivationDelivery => $claimed->confirm($token, $beforeClaim),
            fn(): ActivationDelivery => $claimed->fail($token, $beforeClaim),
            fn(): ActivationDelivery => $claimed->failPermanently($token, $beforeClaim)
        ];
        $rejectedActions = 0;

        foreach ($actions as $action) {
            try {
                $action();
                self::fail('A claim was used before it began.');
            } catch (CredentialDeliveryTransitionException) {
                ++$rejectedActions;
            }
        }

        self::assertSame(count($actions), $rejectedActions);
    }

    public function test_it_rejects_early_claims_and_invalid_leases(): void
    {
        $delivery = $this->delivery();
        $token = CredentialDeliveryClaimToken::generate();

        try {
            $delivery->claim($token, $this->at('11:59:59'), $this->at('12:05:00'));
            self::fail('Early work was claimed.');
        } catch (CredentialDeliveryTransitionException) {
        }

        foreach ([$this->at('12:00:00'), $this->at('13:00:01')] as $leaseUntil) {
            try {
                $delivery->claim($token, $this->at('12:00:00'), $leaseUntil);
                self::fail('An invalid lease was accepted.');
            } catch (CredentialDeliveryTransitionException) {
            }
        }

        self::assertSame(CredentialDeliveryStatus::CLAIMED, $delivery->claim()->getStatus());
    }

    public function test_it_records_delivered_retryable_and_permanent_expected_claim_outcomes(): void
    {
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $this->delivery()->claim($token, $this->at('12:00:00'), $this->at('12:05:00'));
        $delivered = $claimed->confirm($token, $this->at('12:01:00'));

        self::assertSame(CredentialDeliveryStatus::DELIVERED, $delivered->getStatus());
        self::assertFalse($delivered->hasRecoverableMaterial());
        self::assertNull($delivered->getClaimToken());

        $retry = $claimed->fail(
            $token,
            $this->at('12:01:00'),
            CredentialDeliveryFailure::RETRYABLE_PROVIDER
        );
        self::assertSame(CredentialDeliveryStatus::RETRY_PENDING, $retry->getStatus());
        self::assertEquals($this->at('12:02:00'), $retry->getDueAt());
        self::assertSame(CredentialDeliveryFailure::RETRYABLE_PROVIDER, $retry->getLastFailure());
        self::assertTrue($retry->isRetryable());
        self::assertFalse($retry->isDueAt($this->at('12:01:59')));
        self::assertTrue($retry->isDueAt($this->at('12:02:00')));
        self::assertSame(CredentialDeliveryStatus::PENDING, $retry->requestRetry()->getStatus());

        $permanent = $claimed->failPermanently($token, $this->at('12:01:00'));
        self::assertSame(CredentialDeliveryStatus::PERMANENT_FAILURE, $permanent->getStatus());
        self::assertSame(CredentialDeliveryFailure::PERMANENT_PROVIDER, $permanent->getLastFailure());
        self::assertFalse($permanent->hasRecoverableMaterial());
    }

    public function test_it_recovers_expired_leases_and_rejects_stale_claim_outcomes(): void
    {
        $staleToken = CredentialDeliveryClaimToken::generate();
        $claimed = $this->delivery()->claim($staleToken, $this->at('12:00:00'), $this->at('12:05:00'));
        self::assertFalse($claimed->isDueAt($this->at('12:04:59')));
        self::assertTrue($claimed->isDueAt($this->at('12:05:00')));

        $currentToken = CredentialDeliveryClaimToken::generate();
        $reclaimed = $claimed->claim($currentToken, $this->at('12:05:00'), $this->at('12:10:00'));
        self::assertSame(2, $reclaimed->getAttemptCount());

        try {
            $reclaimed->confirm($staleToken, $this->at('12:06:00'));
            self::fail('A stale claimant recorded an outcome.');
        } catch (CredentialDeliveryTransitionException) {
        }

        $retry = $reclaimed->fail(
            $currentToken,
            $this->at('12:06:00'),
            CredentialDeliveryFailure::UNEXPECTED_PROVIDER
        );
        self::assertEquals($this->at('12:08:00'), $retry->getDueAt());
        self::assertSame(CredentialDeliveryFailure::UNEXPECTED_PROVIDER, $retry->getLastFailure());
    }

    public function test_retry_stops_at_expiry_and_terminal_transitions_destroy_material(): void
    {
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $this->delivery()->claim($token, $this->at('12:59:00'), $this->at('12:59:59'));
        $expiredRetry = $claimed->fail(
            $token,
            $this->at('12:59:30'),
            CredentialDeliveryFailure::RETRYABLE_PROVIDER
        );
        self::assertSame(CredentialDeliveryStatus::EXPIRED, $expiredRetry->getStatus());
        self::assertFalse($expiredRetry->hasRecoverableMaterial());

        $delivery = $this->delivery();
        self::assertSame($delivery, $delivery->expireAt($this->at('12:59:59')));
        $expired = $delivery->expireAt($this->at('13:00:00'));
        self::assertSame(CredentialDeliveryStatus::EXPIRED, $expired->getStatus());
        self::assertFalse($expired->hasRecoverableMaterial());
        self::assertFalse($expired->isDueAt($this->at('13:00:00')));

        $invalidated = $delivery->invalidate();
        self::assertSame(CredentialDeliveryStatus::INVALIDATED, $invalidated->getStatus());
        self::assertSame($invalidated, $invalidated->invalidate());
    }

    public function test_it_rejects_stale_or_misclassified_outcomes_and_invalid_retry_requests(): void
    {
        $delivery = $this->delivery();

        foreach ([$delivery->confirm(...), $delivery->fail(...)] as $transition) {
            try {
                $transition();
                self::fail('An outcome without a claim was accepted.');
            } catch (CredentialDeliveryTransitionException) {
            }
        }

        try {
            $delivery->requestRetry();
            self::fail('Pending work was manually retried.');
        } catch (CredentialDeliveryTransitionException) {
        }

        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $delivery->claim($token, $this->at('12:00:00'), $this->at('12:05:00'));
        try {
            $claimed->fail($token, $this->at('12:01:00'), CredentialDeliveryFailure::PERMANENT_PROVIDER);
            self::fail('A permanent failure was recorded as retryable.');
        } catch (CredentialDeliveryTransitionException) {
        }

        $this->expectException(CredentialDeliveryTransitionException::class);
        $claimed->confirm($token, $this->at('12:05:00'));
    }

    public function test_it_rejects_empty_material_and_preserves_runtime_subtypes(): void
    {
        try {
            ActivationDelivery::create(
                ActivationDeliveryId::generate(),
                UserId::generate(),
                EmailAddress::fromString('alice@example.test'),
                '',
                $this->at('13:00:00'),
                $this->at('12:00:00')
            );
            self::fail('Empty encrypted material was accepted.');
        } catch (ActivationDeliveryException $activationDeliveryException) {
            self::assertNotNull($activationDeliveryException->getPrevious());
        }

        $delivery = ExtensibleActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            $this->at('13:00:00'),
            $this->at('12:00:00')
        );
        self::assertInstanceOf(ExtensibleActivationDelivery::class, $delivery->claim());
        self::assertInstanceOf(ExtensibleActivationDelivery::class, $delivery->invalidate());
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25T'.$time.'+00:00');
    }

    private function delivery(): ActivationDelivery
    {
        return ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext',
            $this->at('13:00:00'),
            $this->at('12:00:00')
        );
    }
}
