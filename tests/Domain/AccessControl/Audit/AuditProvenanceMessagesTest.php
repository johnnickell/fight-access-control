<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\UserInvitationDelivered;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\InvitePendingUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserInvited;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditActorKind::class)]
#[CoversClass(AgentCredentialLifecycleFailed::class)]
#[CoversClass(InvitePendingUser::class)]
#[CoversClass(UserInvited::class)]
#[CoversClass(DeliverUserInvitation::class)]
#[CoversClass(ResendInvitationDelivery::class)]
#[CoversClass(RetryInvitationDelivery::class)]
#[CoversClass(InvitationDeliveryResent::class)]
#[CoversClass(InvitationDeliveryRetryRequested::class)]
#[CoversClass(UserInvitationDelivered::class)]
#[CoversClass(DeliverPasswordReset::class)]
#[CoversClass(PasswordResetDeliveryConfirmed::class)]
final class AuditProvenanceMessagesTest extends TestCase
{
    /** @phpstan-return list<Command|Event> */
    private function messages(UserId|AgentId|SystemId|AnonymousId $actor): array
    {
        $user = UserId::generate();
        $delivery = ActivationDeliveryId::generate();
        $resetDelivery = PasswordResetDeliveryId::generate();
        $email = EmailAddress::fromString('example@example.test');
        $at = new DateTimeImmutable('2026-11-09T15:00:00Z');

        return [
            new InvitePendingUser($actor, $user, $email),
            new UserInvited($actor, $user, $delivery, $email, $at),
            new DeliverUserInvitation($actor, $user, $delivery),
            new ResendInvitationDelivery($actor, $user),
            new RetryInvitationDelivery($actor, $user),
            new InvitationDeliveryResent($actor, $user, $delivery),
            new InvitationDeliveryRetryRequested($actor, $user, $delivery),
            new UserInvitationDelivered($actor, $user, $delivery),
            new DeliverPasswordReset($actor, $user, $resetDelivery),
            new PasswordResetDeliveryConfirmed($actor, $user, $resetDelivery, $at),
            new AgentCredentialLifecycleFailed($actor, 'Agent credential lifecycle failed.')
        ];
    }

    public function test_every_affected_message_preserves_direct_actor_kind_and_uuid(): void
    {
        $user = UserId::generate();
        $actors = [$user, AgentId::fromString($user->toString()), SystemId::generate(), AnonymousId::generate()];
        foreach ($actors as $actor) {
            foreach ($this->messages($actor) as $message) {
                $data = $message->toArray();
                self::assertSame(AuditActorKind::forId($actor)->value, $data['actor_kind']);
                self::assertSame($actor->toString(), $data['actor_id']);
                self::assertEquals($message, $message::fromArray($data));
            }
        }
    }

    public function test_lifecycle_failure_can_explicitly_report_unadmitted_provenance_without_fabricating_it(): void
    {
        $event = new AgentCredentialLifecycleFailed(null, 'Agent operation failed.');
        self::assertNull($event->getActorId());
        self::assertSame('Agent operation failed.', $event->getErrorMessage());
        self::assertSame($event->toArray(), AgentCredentialLifecycleFailed::fromArray($event->toArray())->toArray());
        $invalidPayloads = [
            ['actor_id' => UserId::generate()->toString()],
            ['actor_kind' => 'user'],
            ['error_message' => null]
        ];
        foreach ($invalidPayloads as $invalid) {
            try {
                AgentCredentialLifecycleFailed::fromArray(array_replace($event->toArray(), $invalid));
                self::fail('Partial failure provenance or invalid notification was accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_missing_kind_and_labels_never_become_guessed_actors(): void
    {
        foreach ($this->messages(UserId::generate()) as $message) {
            foreach (['missing', 'unknown', 'label'] as $invalid) {
                $data = $message->toArray();
                if ($invalid === 'missing') {
                    unset($data['actor_kind']);
                } elseif ($invalid === 'unknown') {
                    $data['actor_kind'] = 'worker';
                } else {
                    $data['actor_id'] = 'worker-label-not-a-uuid';
                }

                try {
                    $message::fromArray($data);
                    self::fail('Unattributed or label-based input was admitted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }
}
