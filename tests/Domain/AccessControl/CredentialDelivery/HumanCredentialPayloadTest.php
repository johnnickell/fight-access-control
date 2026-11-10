<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\CredentialDelivery;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command as ActivationCommand;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event as ActivationEvent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command as EmailCommand;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event as EmailEvent;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command as ResetCommand;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event as ResetEvent;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Query\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(ActivationCommand\DeliverUserInvitation::class)]
#[CoversClass(ActivationCommand\ExpireInvitationDelivery::class)]
#[CoversClass(ActivationCommand\ResendInvitationDelivery::class)]
#[CoversClass(ActivationCommand\RetryInvitationDelivery::class)]
#[CoversClass(ActivationEvent\InvitationDeliveryExpired::class)]
#[CoversClass(ActivationEvent\InvitationDeliveryResent::class)]
#[CoversClass(ActivationEvent\InvitationDeliveryRetryRequested::class)]
#[CoversClass(ActivationEvent\UserInvitationDelivered::class)]
#[CoversClass(FindInvitationDeliveryStatus::class)]
#[CoversClass(FindCredentialDeliveryStatus::class)]
#[CoversClass(FindDueCredentialDeliveries::class)]
#[CoversClass(FindExpiredCredentialDeliveries::class)]
#[CoversClass(CredentialDeliveryTimestamp::class)]
#[CoversClass(EmailCommand\CancelEmailChange::class)]
#[CoversClass(EmailCommand\DeliverEmailChange::class)]
#[CoversClass(EmailCommand\ExpireEmailChange::class)]
#[CoversClass(EmailCommand\RequestEmailChange::class)]
#[CoversClass(EmailEvent\EmailChangeCancelled::class)]
#[CoversClass(EmailEvent\EmailChangeDelivered::class)]
#[CoversClass(EmailEvent\EmailChangeExpired::class)]
#[CoversClass(EmailEvent\EmailChangeRequested::class)]
#[CoversClass(ResetCommand\DeliverPasswordReset::class)]
#[CoversClass(ResetCommand\ExpirePasswordResetDelivery::class)]
#[CoversClass(ResetCommand\RequestPasswordReset::class)]
#[CoversClass(ResetEvent\PasswordResetDeliveryConfirmed::class)]
#[CoversClass(ResetEvent\PasswordResetDeliveryExpired::class)]
#[CoversClass(ResetEvent\PasswordResetRequested::class)]
final class HumanCredentialPayloadTest extends TestCase
{
    /** @return iterable<string, array{class-string<Command|Event|Query>, array<string, int|string>}> */
    public static function payloads(): iterable
    {
        $id = '018f0000-0000-7000-8000-000000000001';
        $at = '2030-01-01T12:00:00+00:00';
        $actorUser = ['actor_id' => $id, 'user_id' => $id];
        $activation = $actorUser + ['activation_delivery_id' => $id];
        $reset = $actorUser + ['password_reset_delivery_id' => $id];
        $email = $actorUser + ['email_change_delivery_id' => $id];
        $expiry = $actorUser + ['email_change_grant_id' => $id, 'occurred_at' => $at];
        $cases = [
            ActivationCommand\DeliverUserInvitation::class          => $activation,
            ActivationCommand\ExpireInvitationDelivery::class       => $activation + ['occurred_at' => $at],
            ActivationCommand\ResendInvitationDelivery::class       => $actorUser,
            ActivationCommand\RetryInvitationDelivery::class        => $actorUser,
            ActivationEvent\InvitationDeliveryExpired::class        => $activation + ['occurred_at' => $at],
            ActivationEvent\InvitationDeliveryResent::class         => $activation,
            ActivationEvent\InvitationDeliveryRetryRequested::class => $activation,
            ActivationEvent\UserInvitationDelivered::class          => $activation,
            FindInvitationDeliveryStatus::class                     => ['user_id' => $id],
            FindCredentialDeliveryStatus::class                     => [
                'purpose'     => 'activation',
                'delivery_id' => $id
            ],
            FindDueCredentialDeliveries::class                      => ['at' => $at, 'limit' => 50],
            FindExpiredCredentialDeliveries::class                  => ['at' => $at, 'limit' => 50],
            EmailCommand\CancelEmailChange::class                   => $actorUser,
            EmailCommand\DeliverEmailChange::class                  => $email,
            EmailCommand\ExpireEmailChange::class                   => $expiry,
            EmailCommand\RequestEmailChange::class                  => $actorUser + [
                'email' => 'recipient@example.test'
            ],
            EmailEvent\EmailChangeCancelled::class                  => $actorUser + [
                'email_change_grant_id' => $id,
                'cancelled_at'          => $at
            ],
            EmailEvent\EmailChangeDelivered::class                  => $email,
            EmailEvent\EmailChangeExpired::class                    => $expiry,
            EmailEvent\EmailChangeRequested::class                  => $email + [
                'email'     => 'recipient@example.test',
                'issued_at' => $at
            ],
            ResetCommand\DeliverPasswordReset::class                => $reset,
            ResetCommand\ExpirePasswordResetDelivery::class         => $reset + ['occurred_at' => $at],
            ResetCommand\RequestPasswordReset::class                => ['email' => 'recipient@example.test'],
            ResetEvent\PasswordResetDeliveryConfirmed::class        => $reset + ['occurred_at' => $at],
            ResetEvent\PasswordResetDeliveryExpired::class          => $reset + ['occurred_at' => $at],
            ResetEvent\PasswordResetRequested::class                => [
                'user_id'                    => $id,
                'password_reset_delivery_id' => $id,
                'issued_at'                  => $at
            ]
        ];
        foreach ($cases as $class => $payload) {
            if (
                in_array($class, [
                ActivationCommand\DeliverUserInvitation::class,
                ActivationCommand\ResendInvitationDelivery::class,
                ActivationCommand\RetryInvitationDelivery::class,
                ActivationEvent\InvitationDeliveryResent::class,
                ActivationEvent\InvitationDeliveryRetryRequested::class,
                ActivationEvent\UserInvitationDelivered::class,
                ResetCommand\DeliverPasswordReset::class,
                ResetEvent\PasswordResetDeliveryConfirmed::class
                ], true)
            ) {
                $payload = ['actor_kind' => 'user'] + $payload;
            }

            yield $class => [$class, $payload];
        }
    }

    /** @param class-string<Command|Event|Query> $class
     * @param array<string, int|string> $payload
     */
    #[DataProvider('payloads')]
    public function test_current_payloads_have_canonical_round_trips(string $class, array $payload): void
    {
        $message = $class::fromArray($payload);
        $canonical = $message->toArray();
        self::assertSame(array_keys($payload), array_keys($canonical));
        self::assertSame($canonical, $class::fromArray($canonical)->toArray());
    }

    /** @return iterable<string, array{class-string<Command|Event|Query>, array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::payloads() as $name => [$class, $payload]) {
            foreach ($payload as $key => $valid) {
                $missing = $payload;
                unset($missing[$key]);
                yield $name.' missing '.$key => [$class, $missing];
                foreach ([null, false, 1.5, [], 1] as $index => $value) {
                    if (is_int($valid) && is_int($value)) {
                        $value = '50';
                    }

                    yield $name.' '.$key.' type '.$index => [$class, array_replace($payload, [$key => $value])];
                }

                if (is_string($valid)) {
                    $object = new readonly class ($valid) implements Stringable {
                        public function __construct(private string $value)
                        {
                        }

                        public function __toString(): string
                        {
                            return $this->value;
                        }
                    };
                    yield $name.' '.$key.' object' => [$class, array_replace($payload, [$key => $object])];
                }
            }
        }
    }

    /** @param class-string<Command|Event|Query> $class
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_decoding_rejects_missing_and_wrong_type_fields_without_coercion(
        string $class,
        array $payload
    ): void {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }

    /** @return iterable<string, array{class-string<Command|Event|Query>, array<string, int|string>}> */
    public static function invalidInstants(): iterable
    {
        foreach (self::payloads() as $name => [$class, $payload]) {
            foreach (['at', 'issued_at', 'occurred_at', 'cancelled_at'] as $key) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }

                foreach (['', 'tomorrow', '2030-02-30T12:00:00Z', '2030-01-01'] as $index => $value) {
                    yield $name.' '.$key.' instant '.$index => [$class, array_replace($payload, [$key => $value])];
                }
            }
        }
    }

    /** @param class-string<Command|Event|Query> $class
     * @param array<string, int|string> $payload
     */
    #[DataProvider('invalidInstants')]
    public function test_decoding_requires_explicit_valid_calendar_instants(string $class, array $payload): void
    {
        $this->expectException(DomainException::class);
        $class::fromArray($payload);
    }
}
