<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler as Activation;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler as Email;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler as Reset;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command as ActivationCommand;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command as EmailCommand;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command as ResetCommand;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\ExpiredCredentialFixture;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\{
    FixedEmailChangeAdministrationAuthorization
};
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\FixedEmailChangeCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\PrefixEmailChangeDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;

final readonly class HumanCredentialContractFixture
{
    public ExpiredCredentialFixture $state;

    public RecordingCredentialDeliveryProvider $provider;

    public UserId $actorId;

    public function __construct(public string $purpose)
    {
        $this->actorId = UserId::generate();
        $this->state = new ExpiredCredentialFixture($purpose);
        $this->provider = new RecordingCredentialDeliveryProvider();
    }

    public function command(string $operation): Command
    {
        $userId = $this->state->user->getId();
        $deliveryId = $this->state->initial->getDelivery()->getId();

        return match ($operation) {
            'activation.deliver' => new ActivationCommand\DeliverUserInvitation($this->actorId, $userId, $deliveryId),
            'activation.expire' => new ActivationCommand\ExpireInvitationDelivery(
                $this->actorId,
                $userId, $deliveryId, $this->state->expiresAt
            ),
            'activation.resend' => new ActivationCommand\ResendInvitationDelivery($this->actorId, $userId),
            'activation.retry' => new ActivationCommand\RetryInvitationDelivery($this->actorId, $userId),
            'password_reset.deliver' => new ResetCommand\DeliverPasswordReset($this->actorId, $userId, $deliveryId),
            'password_reset.expire' => new ResetCommand\ExpirePasswordResetDelivery(
                'worker',
                $userId, $deliveryId, $this->state->expiresAt
            ),
            'password_reset.request' => new ResetCommand\RequestPasswordReset($this->state->user->getEmail()),
            'email_change.deliver' => new EmailCommand\DeliverEmailChange($userId, $userId, $deliveryId),
            'email_change.expire' => new EmailCommand\ExpireEmailChange(
                SystemId::generate(),
                $userId, $this->state->initial->getId(), $this->state->expiresAt
            ),
            'email_change.request' => new EmailCommand\RequestEmailChange(
                $userId,
                $userId, EmailAddress::fromString('another@example.test')
            ),
            'email_change.cancel' => new EmailCommand\CancelEmailChange($userId, $userId),
            default => throw new LogicException('Unknown fixture operation.')
        };
    }

    public function handler(
        string $operation,
        ?ActivationGrantRepository $activation = null,
        ?PasswordResetGrantRepository $reset = null,
        ?EmailChangeGrantRepository $email = null,
        ?UserRepository $users = null,
        ?Clock $clock = null,
        ?EmailChangeAdministrationAuthorization $authorization = null
    ): CommandHandler {
        $activation ??= $this->state->activationGrantRepository;
        $reset ??= $this->state->passwordResetGrantRepository;
        $email ??= $this->state->emailChangeGrantRepository;
        $users ??= $this->state->userRepository;
        $audit = new InMemoryAuditEvidenceRepository($this->state->unitOfWork);
        $clock ??= new FixedClock($this->state->issuedAt->format('Y-m-d\TH:i:s.uP'));
        $unitOfWork = $this->state->unitOfWork;
        $events = $this->state->eventDispatcher;
        $authorization ??= new FixedEmailChangeAdministrationAuthorization(true);

        return match ($operation) {
            'activation.deliver' => new Activation\DeliverUserInvitationHandler(
                $activation,
                $unitOfWork, new PrefixInvitationDeliveryCipher(), $this->provider, $clock, $events, $audit
            ),
            'activation.expire' => new Activation\ExpireInvitationDeliveryHandler($activation, $unitOfWork, $events),
            'activation.resend' => new Activation\ResendInvitationDeliveryHandler(
                $activation,
                $unitOfWork, new FixedCredentialGenerator('fresh'),
                new PrefixInvitationDeliveryCipher(), $clock, $events, $audit
            ),
            'activation.retry' => new Activation\RetryInvitationDeliveryHandler(
                $activation,
                $unitOfWork, $clock, $events, $audit
            ),
            'password_reset.deliver' => new Reset\DeliverPasswordResetHandler(
                $reset,
                $audit, $unitOfWork, new PrefixPasswordResetDeliveryCipher(), $this->provider, $clock, $events, $users
            ),
            'password_reset.expire' => new Reset\ExpirePasswordResetDeliveryHandler($reset, $unitOfWork, $events),
            'password_reset.request' => new Reset\RequestPasswordResetHandler(
                $users,
                $reset, $audit, $unitOfWork, new FixedPasswordResetCredentialGenerator('fresh'),
                new PrefixPasswordResetDeliveryCipher(), $clock, $events
            ),
            'email_change.deliver' => new Email\DeliverEmailChangeHandler(
                $email,
                $unitOfWork, new PrefixEmailChangeDeliveryCipher(), $this->provider, $clock, $events, $audit
            ),
            'email_change.expire' => new Email\ExpireEmailChangeHandler($users, $email, $unitOfWork, $events),
            'email_change.request' => new Email\RequestEmailChangeHandler(
                $users,
                $email, $authorization, $unitOfWork,
                new FixedEmailChangeCredentialGenerator('fresh'), new PrefixEmailChangeDeliveryCipher(),
                $clock, $events, $audit
            ),
            'email_change.cancel' => new Email\CancelEmailChangeHandler(
                $users,
                $email, $authorization, $unitOfWork, $clock, $events, $audit
            ),
            default => throw new LogicException('Unknown fixture operation.')
        };
    }
}
