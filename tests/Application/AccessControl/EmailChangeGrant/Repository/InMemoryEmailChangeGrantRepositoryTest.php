<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryEmailChangeGrantRepositoryTest extends TestCase
{
    public function test_it_discovers_only_due_authoritative_email_change_work(): void
    {
        $repository = new InMemoryEmailChangeGrantRepository();
        $grant = $this->grant(UserId::generate(), 'due', 'due@example.test');
        self::assertTrue($repository->add($grant));
        self::assertSame([], $repository->findDue(new DateTimeImmutable('2026-08-22T11:59:59+00:00'), 10));
        self::assertSame(
            $grant->getDelivery()->getId()->toString(),
            $repository->findDue(new DateTimeImmutable('2026-08-22T12:00:00+00:00'), 10)[0]
                ->getDeliveryId()
                ->toString()
        );

        $claimed = $grant->claimDelivery(
            CredentialDeliveryClaimToken::generate(),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T12:05:00+00:00')
        );
        self::assertTrue($repository->replace($grant, $claimed));
        self::assertSame([], $repository->findDue(new DateTimeImmutable('2026-08-22T12:04:59+00:00'), 10));
        self::assertCount(1, $repository->findDue(new DateTimeImmutable('2026-08-22T12:05:00+00:00'), 10));
    }

    public function test_initial_add_rejects_duplicate_grant_and_delivery_identifiers_without_mutation(): void
    {
        $repository = new InMemoryEmailChangeGrantRepository();
        $stored = $this->grant(UserId::generate(), 'stored', 'stored@example.test');
        self::assertTrue($repository->add($stored));

        $duplicateGrantTemplate = $this->grant(
            UserId::generate(),
            'duplicate-grant',
            'duplicate-grant@example.test'
        );
        $duplicateGrant = FabricatedEmailChangeGrant::withIdentifiers(
            $duplicateGrantTemplate,
            $stored->getId(),
            $duplicateGrantTemplate->getDelivery()->getId()
        );
        self::assertFalse($repository->add($duplicateGrant));

        $duplicateDeliveryTemplate = $this->grant(
            UserId::generate(),
            'duplicate-delivery',
            'duplicate-delivery@example.test'
        );
        $duplicateDelivery = FabricatedEmailChangeGrant::withIdentifiers(
            $duplicateDeliveryTemplate,
            $duplicateDeliveryTemplate->getId(),
            $stored->getDelivery()->getId()
        );
        self::assertFalse($repository->add($duplicateDelivery));
        self::assertSame([$stored], $repository->all());
    }

    public function test_only_the_authoritative_terminal_predecessor_can_append_a_fresh_successor(): void
    {
        $repository = new InMemoryEmailChangeGrantRepository();
        $userId = UserId::generate();
        $issued = $this->grant($userId, 'predecessor', 'first@example.test');
        self::assertTrue($repository->add($issued));
        $terminal = $issued->revoke(new DateTimeImmutable('2026-08-22T11:00:00+00:00'));
        self::assertTrue($repository->replace($issued, $terminal));
        $winner = $this->grant($userId, 'winner', 'winner@example.test');
        $staleCandidate = $this->grant($userId, 'stale', 'stale@example.test');

        self::assertTrue($repository->appendAfterTerminal($terminal, $winner));
        self::assertFalse($repository->appendAfterTerminal($terminal, $staleCandidate));
        self::assertSame([$terminal, $winner], $repository->all());
    }

    public function test_successor_with_delivery_work_beyond_its_grant_expiry_is_rejected(): void
    {
        $repository = new InMemoryEmailChangeGrantRepository();
        $userId = UserId::generate();
        $issued = $this->grant($userId, 'predecessor', 'first@example.test');
        self::assertTrue($repository->add($issued));
        $terminal = $issued->revoke(new DateTimeImmutable('2026-08-22T11:00:00+00:00'));
        self::assertTrue($repository->replace($issued, $terminal));
        $successor = FabricatedEmailChangeGrant::withDeliveryExpiry(
            $this->grant($userId, 'successor', 'successor@example.test'),
            new DateTimeImmutable('2026-08-22T14:00:00+00:00')
        );

        self::assertFalse($repository->appendAfterTerminal($terminal, $successor));
        self::assertSame([$terminal], $repository->all());
        self::assertSame([], $repository->findDue(new DateTimeImmutable('2026-08-22T13:30:00+00:00'), 10));
    }

    public function test_fabricated_terminal_state_and_historical_digest_reuse_are_rejected(): void
    {
        $repository = new InMemoryEmailChangeGrantRepository();
        $userId = UserId::generate();
        $issued = $this->grant($userId, 'historical', 'first@example.test');
        self::assertTrue($repository->add($issued));
        $terminal = $issued->consume(new DateTimeImmutable('2026-08-22T11:00:00+00:00'));
        self::assertTrue($repository->replace($issued, $terminal));
        $successor = $this->grant($userId, 'successor', 'successor@example.test');
        $fabricatedTerminal = FabricatedEmailChangeGrant::fromGrant($terminal);

        self::assertFalse($repository->appendAfterTerminal($fabricatedTerminal, $successor));
        self::assertFalse($repository->appendAfterTerminal(
            $terminal,
            $this->grant($userId, 'historical', 'reused@example.test')
        ));
        self::assertSame([$terminal], $repository->all());
    }

    private function grant(UserId $userId, string $credential, string $email): EmailChangeGrant
    {
        return EmailChangeGrant::issue(
            $userId,
            EmailChangeCredential::fromString($credential),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString($email),
            'ciphertext:'.$credential
        );
    }
}
