<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;

final class ActivationOriginFixture
{
    public InMemoryActivationGrantRepository $activationGrantRepository;

    public readonly InMemoryAuditEvidenceRepository $auditEvidenceRepository;

    public readonly InMemoryUserRepository $userRepository;

    public readonly ActivationGrant $initial;

    public readonly User $user;

    public readonly InMemoryUnitOfWork $unitOfWork;

    public function __construct(ActivationOrigin $origin, ?InMemoryUnitOfWork $unitOfWork = null)
    {
        $this->unitOfWork = $unitOfWork ?? new InMemoryUnitOfWork();
        $this->activationGrantRepository = new InMemoryActivationGrantRepository($this->unitOfWork);
        $this->auditEvidenceRepository = new InMemoryAuditEvidenceRepository($this->unitOfWork);
        $this->userRepository = new InMemoryUserRepository($this->unitOfWork);
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $this->user = User::invite(UserId::generate(), EmailAddress::fromString('origin@example.test'), $at);
        $this->initial = ActivationGrant::issue(
            $origin,
            $this->user->getId(),
            ActivationCredential::fromString('origin-fixture'),
            $at,
            $at->modify('+1 day'),
            $this->user->getEmail(),
            'ciphertext:origin-fixture'
        );
        $this->userRepository->add($this->user);
        $this->activationGrantRepository->add($this->initial);
    }

    public function worker(
        RecordingCredentialDeliveryProvider $provider,
        string $at = '2030-01-01T00:00:00Z',
        ?InMemoryEventDispatcher $eventDispatcher = null,
        ?TransactionalUnitOfWork $unitOfWork = null
    ): DeliverUserInvitationHandler {
        return new DeliverUserInvitationHandler(
            $this->activationGrantRepository,
            $this->auditEvidenceRepository,
            $unitOfWork ?? $this->unitOfWork,
            new PrefixInvitationDeliveryCipher(),
            $provider,
            new FixedClock($at),
            $eventDispatcher ?? new InMemoryEventDispatcher()
        );
    }

    public function resend(?InMemoryEventDispatcher $eventDispatcher = null): ResendInvitationDeliveryHandler
    {
        return new ResendInvitationDeliveryHandler(
            $this->activationGrantRepository,
            $this->auditEvidenceRepository,
            $this->unitOfWork,
            new FixedCredentialGenerator('successor-fixture'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2030-01-01T00:01:00Z'),
            $eventDispatcher ?? new InMemoryEventDispatcher()
        );
    }

    /** @return list<DueCredentialDelivery> */
    public function discover(string $at): array
    {
        return new FindDueCredentialDeliveriesHandler(
            $this->activationGrantRepository,
            new InMemoryPasswordResetGrants(),
            new InMemoryEmailChangeGrantRepository()
        )->handle(QueryMessage::create(new FindDueCredentialDeliveries(new DateTimeImmutable($at), 50)));
    }
}
