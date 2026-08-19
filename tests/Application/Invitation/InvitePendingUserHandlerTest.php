<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Application\Invitation\ActivationDeliveryWork;
use Fight\AccessControl\Application\Invitation\AuditEvidence;
use Fight\AccessControl\Application\Invitation\DuplicateEmail;
use Fight\AccessControl\Application\Invitation\InvitationView;
use Fight\AccessControl\Application\Invitation\InvitePendingUser;
use Fight\AccessControl\Application\Invitation\InvitePendingUserHandler;
use Fight\AccessControl\Domain\Identity\ActivationGrant;
use Fight\AccessControl\Domain\Identity\User;
use Fight\AccessControl\Domain\Identity\UserState;
use Fight\Test\AccessControl\Domain\Identity\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InvitePendingUserHandler::class)]
#[CoversClass(ActivationDeliveryWork::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(InvitationView::class)]
#[CoversClass(InvitePendingUser::class)]
#[CoversClass(User::class)]
final class InvitePendingUserHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{UserState}>
     */
    public static function reservingUserStates(): iterable
    {
        yield 'pending activation' => [UserState::PendingActivation];
        yield 'active' => [UserState::Active];
        yield 'disabled' => [UserState::Disabled];
        yield 'deleted' => [UserState::Deleted];
    }

    public function test_it_records_an_invitation_as_one_atomic_durable_operation(): void
    {
        $users = new InMemoryUserStore();
        $grants = new InMemoryActivationGrantStore();
        $deliveries = new InMemoryActivationDeliveryWorkStore();
        $audits = new InMemoryAuditEvidenceStore();
        $unitOfWork = new InMemoryUnitOfWork();
        $handler = new InvitePendingUserHandler(
            $users,
            $grants,
            $deliveries,
            $audits,
            $unitOfWork,
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            new FixedInvitationClock('2026-08-18T12:00:00+00:00')
        );

        $invitation = $handler(new InvitePendingUser('Admin-42', ' Alice@example.test '));

        self::assertSame('alice@example.test', $invitation->email());
        self::assertSame('pending_activation', $invitation->state());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $users->all());
        self::assertCount(1, $grants->all());
        self::assertCount(1, $deliveries->all());
        self::assertCount(1, $audits->all());
        self::assertSame('activation', $grants->all()[0]->purpose());
        self::assertNotSame('activate-once', $grants->all()[0]->credentialHash());
        self::assertSame($users->all()[0]->id(), $grants->all()[0]->userId());
        self::assertSame('2026-08-25T12:00:00+00:00', $grants->all()[0]->expiresAt()->format(DATE_ATOM));
        self::assertSame($users->all()[0]->id(), $deliveries->all()[0]->userId());
        self::assertSame($grants->all()[0]->userId(), $deliveries->all()[0]->userId());
        self::assertSame('alice@example.test', $deliveries->all()[0]->email());
        self::assertSame('ciphertext:activate-once', $deliveries->all()[0]->ciphertext());
        self::assertSame($grants->all()[0]->expiresAt(), $deliveries->all()[0]->expiresAt());
        self::assertSame('Admin-42', $audits->all()[0]->actorId());
        self::assertSame('user.invited', $audits->all()[0]->action());
        self::assertSame($users->all()[0]->id(), $audits->all()[0]->userId());
        self::assertSame([], $audits->all()[0]->context());
    }

    #[DataProvider('reservingUserStates')]
    public function test_it_rejects_a_canonical_email_reserved_by_every_user_state_without_staging_more_durable_work(
        UserState $state
    ): void {
        $users = new InMemoryUserStore();
        self::assertTrue($users->reserve(UserFixture::withState('alice@example.test', $state)));

        $grants = new InMemoryActivationGrantStore();
        $deliveries = new InMemoryActivationDeliveryWorkStore();
        $audits = new InMemoryAuditEvidenceStore();
        $handler = new InvitePendingUserHandler(
            $users,
            $grants,
            $deliveries,
            $audits,
            new InMemoryUnitOfWork(),
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            new FixedInvitationClock('2026-08-18T12:00:00+00:00')
        );

        try {
            $handler(new InvitePendingUser('Admin-42', 'ALICE@example.test'));
            self::fail('A canonical email conflict must reject the invitation.');
        } catch (DuplicateEmail) {
        }

        self::assertCount(1, $users->all());
        self::assertCount(0, $grants->all());
        self::assertCount(0, $deliveries->all());
        self::assertCount(0, $audits->all());
    }

    public function test_it_rolls_back_every_durable_invitation_record_when_a_late_save_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserStore($unitOfWork);
        $grants = new InMemoryActivationGrantStore($unitOfWork);
        $deliveries = new InMemoryActivationDeliveryWorkStore($unitOfWork);
        $audits = new InMemoryAuditEvidenceStore($unitOfWork, failAfterSave: true);
        $handler = new InvitePendingUserHandler(
            $users,
            $grants,
            $deliveries,
            $audits,
            $unitOfWork,
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            new FixedInvitationClock('2026-08-18T12:00:00+00:00')
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler(new InvitePendingUser('Admin-42', 'alice@example.test'));
        } finally {
            self::assertCount(0, $users->all());
            self::assertCount(0, $grants->all());
            self::assertCount(0, $deliveries->all());
            self::assertCount(0, $audits->all());
        }
    }

    public function test_it_reads_the_current_time_for_each_invitation(): void
    {
        $grants = new InMemoryActivationGrantStore();
        $clock = new FixedInvitationClock(
            '2026-08-18T12:00:00+00:00',
            '2026-08-19T12:00:00+00:00'
        );
        $handler = new InvitePendingUserHandler(
            new InMemoryUserStore(),
            $grants,
            new InMemoryActivationDeliveryWorkStore(),
            new InMemoryAuditEvidenceStore(),
            new InMemoryUnitOfWork(),
            new FixedCredentialGenerator('activate-once'),
            new PrefixCipher(),
            $clock
        );

        $handler(new InvitePendingUser('Admin-42', 'alice@example.test'));
        $handler(new InvitePendingUser('Admin-42', 'bob@example.test'));

        self::assertSame(2, $clock->calls());
        self::assertSame('2026-08-25T12:00:00+00:00', $grants->all()[0]->expiresAt()->format(DATE_ATOM));
        self::assertSame('2026-08-26T12:00:00+00:00', $grants->all()[1]->expiresAt()->format(DATE_ATOM));
    }
}
