<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;
use ValueError;

#[CoversClass(ActivationGrant::class)]
final class ActivationOriginTest extends TestCase
{
    /** @return list<array{mixed}> */
    public static function invalidHydrationOrigins(): array
    {
        return [[null], ['registration'], [1]];
    }

    public function test_each_current_origin_survives_every_generation_transition_and_hydration(): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        foreach (ActivationOrigin::cases() as $origin) {
            $grant = ActivationGrant::issue(
                $origin,
                UserId::generate(),
                ActivationCredential::fromString('fixture'),
                $at,
                $at->modify('+1 day'),
                EmailAddress::fromString('origin@example.test'),
                'ciphertext:fixture'
            );
            $token = CredentialDeliveryClaimToken::generate();
            $claimed = $grant->claimDelivery($token, $at, $at->modify('+5 minutes'));
            $failed = $claimed->failDelivery($token, $at, CredentialDeliveryFailure::RETRYABLE_PROVIDER);
            $states = [
                $grant,
                $claimed,
                $failed,
                $failed->requestDeliveryRetry(),
                $claimed->confirmDelivery($token, $at),
                $claimed->failDeliveryPermanently($token, $at),
                $grant->expireDeliveryAt($grant->getExpiresAt()),
                $grant->consume($at),
                $grant->revoke($at)
            ];
            foreach ($states as $state) {
                $hydrated = ExtensibleActivationGrant::reconstitute(
                    ActivationOrigin::from($state->getOrigin()->value),
                    $state->getId(),
                    $state->getUserId(),
                    $state->getCredentialHash(),
                    $state->getExpiresAt(),
                    clone $state->getDelivery(),
                    $state->getConsumedAt(),
                    $state->getRevokedAt(),
                    $state->getRevision()
                );
                self::assertSame($origin, $state->getOrigin());
                self::assertSame($origin, $hydrated->getOrigin());
                self::assertEquals($state->getDelivery(), $hydrated->getDelivery());
                self::assertSame($state->getRevision(), $hydrated->getRevision());
            }
        }
    }

    public function test_unsupported_persisted_origins_have_no_fallback(): void
    {
        foreach (['', 'activation', 'invitation', 'unknown', 'REGISTRATION'] as $invalid) {
            try {
                ActivationOrigin::from($invalid);
                self::fail('An unsupported persisted origin was accepted.');
            } catch (ValueError) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidHydrationOrigins')]
    public function test_missing_or_untyped_current_origin_cannot_hydrate_a_grant(mixed $missing): void
    {
        $this->expectException(TypeError::class);
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $grant = ActivationGrant::issue(
            ActivationOrigin::REGISTRATION,
            UserId::generate(),
            ActivationCredential::fromString('fixture'),
            $at,
            $at->modify('+1 day'),
            EmailAddress::fromString('origin@example.test'),
            'ciphertext:fixture'
        );
        // Exercise an untyped persistence value with otherwise complete current state.
        ExtensibleActivationGrant::reconstitute(
            $missing,
            $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            $grant->getDelivery()
        );
    }
}
