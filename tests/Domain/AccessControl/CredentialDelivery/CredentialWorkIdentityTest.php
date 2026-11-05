<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDelivery;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DueCredentialDelivery::class)]
#[CoversClass(ExpiredCredentialDelivery::class)]
#[CoversClass(CredentialDeliveryStatusView::class)]
final class CredentialWorkIdentityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ActivationDeliveryId|PasswordResetDeliveryId|EmailChangeDeliveryId}>
     */
    public static function invalidIdentities(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change', 'unsupported'] as $purpose) {
            $identities = [ActivationDeliveryId::class, PasswordResetDeliveryId::class, EmailChangeDeliveryId::class];
            foreach ($identities as $id) {
                $expected = match ($purpose) {
                    'activation' => ActivationDeliveryId::class,
                    'password_reset' => PasswordResetDeliveryId::class,
                    'email_change' => EmailChangeDeliveryId::class,
                    default => null
                };
                if ($id !== $expected) {
                    yield $purpose.' '.$id => [$purpose, $id::generate()];
                }
            }
        }
    }

    #[DataProvider('invalidIdentities')]
    public function test_due_work_rejects_cross_purpose_identity(
        string $purpose,
        ActivationDeliveryId|PasswordResetDeliveryId|EmailChangeDeliveryId $id
    ): void {
        $this->expectException(DomainException::class);
        new DueCredentialDelivery(
            $purpose,
            $id,
            UserId::generate(),
            new DateTimeImmutable('2030-01-01T12:00:00Z'),
            0,
            CredentialDeliveryStatus::PENDING
        );
    }

    public function test_due_work_rejects_negative_revision(): void
    {
        $this->expectException(DomainException::class);
        new DueCredentialDelivery(
            'activation',
            ActivationDeliveryId::generate(),
            UserId::generate(),
            new DateTimeImmutable('2030-01-01T12:00:00Z'),
            -1,
            CredentialDeliveryStatus::PENDING
        );
    }

    /** @return iterable<string, array{CredentialDeliveryStatus}> */
    public static function terminalStatuses(): iterable
    {
        foreach (
            [
                CredentialDeliveryStatus::DELIVERED,
                CredentialDeliveryStatus::EXPIRED,
                CredentialDeliveryStatus::INVALIDATED,
                CredentialDeliveryStatus::PERMANENT_FAILURE
            ] as $status
        ) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('terminalStatuses')]
    public function test_due_work_cannot_describe_terminal_work(CredentialDeliveryStatus $status): void
    {
        $this->expectException(DomainException::class);
        new DueCredentialDelivery(
            'activation',
            ActivationDeliveryId::generate(),
            UserId::generate(),
            new DateTimeImmutable('2030-01-01T12:00:00Z'),
            0,
            $status
        );
    }

    #[DataProvider('invalidIdentities')]
    public function test_status_projection_rejects_cross_purpose_delivery(
        string $purpose,
        ActivationDeliveryId|PasswordResetDeliveryId|EmailChangeDeliveryId $id
    ): void {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $delivery = match (true) {
            $id instanceof ActivationDeliveryId => ActivationDelivery::create(
                $id,
                UserId::generate(),
                EmailAddress::fromString('recipient@example.test'),
                'test-material',
                $at->modify('+1 hour'),
                $at
            ),
            $id instanceof PasswordResetDeliveryId => PasswordResetDelivery::create(
                $id,
                UserId::generate(),
                EmailAddress::fromString('recipient@example.test'),
                'test-material',
                $at->modify('+1 hour'),
                $at
            ),
            default => EmailChangeDelivery::create(
                $id,
                UserId::generate(),
                EmailAddress::fromString('recipient@example.test'),
                'test-material',
                $at->modify('+1 hour'),
                $at
            )
        };
        $this->expectException(DomainException::class);
        CredentialDeliveryStatusView::fromDelivery($purpose, $delivery, 0);
    }

    public function test_status_projection_rejects_negative_revision(): void
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $delivery = ActivationDelivery::create(
            ActivationDeliveryId::generate(),
            UserId::generate(),
            EmailAddress::fromString('recipient@example.test'),
            'test-material',
            $at->modify('+1 hour'),
            $at
        );
        $this->expectException(DomainException::class);
        CredentialDeliveryStatusView::fromDelivery('activation', $delivery, -1);
    }

    public function test_expiry_work_preserves_the_required_email_grant_relationship(): void
    {
        $deliveryId = EmailChangeDeliveryId::generate();
        $grantId = EmailChangeGrantId::generate();
        $work = new ExpiredCredentialDelivery(
            'email_change',
            $deliveryId,
            UserId::generate(),
            $grantId,
            new DateTimeImmutable('2030-01-01T12:00:00.123456Z'),
            5,
            CredentialDeliveryStatus::DELIVERED
        );
        self::assertSame($deliveryId, $work->getDeliveryId());
        self::assertSame($grantId, $work->getEmailChangeGrantId());
        self::assertSame($work->toArray(), ExpiredCredentialDelivery::fromArray($work->toArray())->toArray());
        $this->expectException(DomainException::class);
        new ExpiredCredentialDelivery(
            'email_change',
            $deliveryId,
            $work->getUserId(),
            null,
            $work->getExpiresAt(),
            5,
            CredentialDeliveryStatus::DELIVERED
        );
    }
}
