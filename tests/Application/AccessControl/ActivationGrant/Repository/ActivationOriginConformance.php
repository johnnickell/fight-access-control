<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant\ExtensibleActivationGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class ActivationOriginConformance extends TestCase
{
    public function test_persisted_origin_survives_restart_and_cannot_be_fabricated_in_either_cas_operand(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            $repository = $this->repository();
            $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
            $grant = ActivationGrant::issue(
                $origin,
                UserId::generate(),
                ActivationCredential::fromString('fixture'),
                $at,
                $at->modify('+1 day'),
                EmailAddress::fromString('origin@example.test'),
                'ciphertext:fixture'
            );
            self::assertTrue($repository->add($grant));
            $repository = $this->restart($repository);
            $stored = $repository->getLatestByUserId($grant->getUserId());
            self::assertNotNull($stored);
            self::assertSame($origin, $stored->getOrigin());
            $claimed = $stored->claimDelivery(CredentialDeliveryClaimToken::generate(), $at, $at->modify('+5 minutes'));
            $otherOrigin = ActivationOrigin::REGISTRATION;
            if ($origin === ActivationOrigin::REGISTRATION) {
                $otherOrigin = ActivationOrigin::PRIVATE_INVITATION;
            }

            self::assertFalse($repository->replace($this->withOrigin($stored, $otherOrigin), $claimed));
            self::assertFalse($repository->replace($stored, $this->withOrigin($claimed, $otherOrigin)));
            self::assertEquals($stored, $repository->getLatestByUserId($stored->getUserId()));
            self::assertTrue($repository->replace($stored, $claimed));
            $repository = $this->restart($repository);
            $current = $repository->getByDeliveryId($grant->getDelivery()->getId());
            self::assertNotNull($current);
            self::assertSame($origin, $current->getOrigin());
            self::assertEquals($claimed->getDelivery(), $current->getDelivery());
            self::assertSame(1, $current->getRevision());
            self::assertFalse($repository->replace($stored, $claimed));
        }
    }

    public function test_successor_cas_cannot_relabel_its_terminal_predecessor_or_revive_obsolete_work(): void
    {
        $repository = $this->repository();
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
        self::assertTrue($repository->add($grant));
        $successor = ActivationGrant::issue(
            ActivationOrigin::REGISTRATION,
            $grant->getUserId(),
            ActivationCredential::fromString('successor'),
            $at,
            $at->modify('+1 day'),
            $grant->getDelivery()->getEmail(),
            'ciphertext:successor'
        );
        $terminal = $grant->revoke($at);
        self::assertFalse($repository->replaceWithSuccessor(
            $this->withOrigin($grant, ActivationOrigin::PRIVATE_INVITATION),
            $terminal,
            $successor
        ));
        self::assertFalse($repository->replaceWithSuccessor(
            $grant,
            $this->withOrigin($terminal, ActivationOrigin::PRIVATE_INVITATION),
            $successor
        ));
        self::assertTrue($repository->replaceWithSuccessor($grant, $terminal, $successor));
        $repository = $this->restart($repository);
        self::assertFalse($repository->replace(
            $grant,
            $grant->claimDelivery(CredentialDeliveryClaimToken::generate(), $at, $at->modify('+5 minutes'))
        ));
        self::assertSame(ActivationOrigin::REGISTRATION, $repository->getById($grant->getId())?->getOrigin());
        self::assertSame(
            $successor->getId()->toString(),
            $repository->getLatestByUserId($grant->getUserId())?->getId()->toString()
        );
    }

    abstract protected function repository(): ActivationGrantRepository;

    abstract protected function restart(ActivationGrantRepository $repository): ActivationGrantRepository;

    private function withOrigin(ActivationGrant $grant, ActivationOrigin $origin): ActivationGrant
    {
        return ExtensibleActivationGrant::reconstitute(
            $origin,
            $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            $grant->getDelivery(),
            $grant->getConsumedAt(),
            $grant->getRevokedAt(),
            $grant->getRevision()
        );
    }
}
