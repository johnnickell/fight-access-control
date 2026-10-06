<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ExpireInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ExpireInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Assert;

final readonly class ExpiredCredentialFixture
{
    public InMemoryUnitOfWork $unitOfWork;

    public InMemoryUserRepository $users;

    public InMemoryActivationGrantRepository $activation;

    public InMemoryPasswordResetGrants $reset;

    public InMemoryEmailChangeGrantRepository $email;

    public InMemoryEventDispatcher $events;

    public User $user;

    public ActivationGrant|PasswordResetGrant|EmailChangeGrant $initial;

    public DateTimeImmutable $issuedAt;

    public DateTimeImmutable $expiresAt;

    public function __construct(public string $purpose)
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->users = new InMemoryUserRepository($this->unitOfWork);
        $this->activation = new InMemoryActivationGrantRepository($this->unitOfWork);
        $this->reset = new InMemoryPasswordResetGrants($this->unitOfWork);
        $this->email = new InMemoryEmailChangeGrantRepository($this->unitOfWork);
        $this->events = new InMemoryEventDispatcher();
        $this->issuedAt = new DateTimeImmutable('2030-01-01T00:00:00.123456Z');
        $this->expiresAt = new DateTimeImmutable('2030-01-01T01:00:00.123456Z');
        $this->user = UserFixture::withIdAndAuthenticationVersion(
            UserId::generate(),
            'old@example.test',
            UserState::PENDING_ACTIVATION,
            4
        );
        $this->user->activate(
            PasswordHash::fromString(password_hash('fixture-only-password', PASSWORD_BCRYPT, ['cost' => 4])),
            $this->issuedAt
        );
        $this->user->advanceAuthenticationAuthorityRevision();
        $this->user->assignRole(RoleId::generate(), $this->issuedAt);
        if ($purpose === 'email_change') {
            $this->user->requestEmailChange(EmailAddress::fromString('next@example.test'), $this->issuedAt);
        }

        $this->users->add($this->user);
        $arguments = [
            $this->user->getId(),
            $this->issuedAt,
            $this->expiresAt,
            EmailAddress::fromString('next@example.test')
        ];
        $this->initial = match ($purpose) {
            'activation' => ActivationGrant::issue(
                $arguments[0],
                ActivationCredential::fromString('fixture'),
                $arguments[1],
                $arguments[2],
                $arguments[3],
                'fixture:encrypted'
            ),
            'password_reset' => PasswordResetGrant::issue(
                $arguments[0],
                PasswordResetCredential::fromString('fixture'),
                $arguments[1],
                $arguments[2],
                $arguments[3],
                'fixture:encrypted'
            ),
            'email_change' => EmailChangeGrant::issue(
                $arguments[0],
                EmailChangeCredential::fromString('fixture'),
                $arguments[1],
                $arguments[2],
                $arguments[3],
                'fixture:encrypted',
                $this->user->getEmailChangeReservationRevision()
            ),
            default => throw new LogicException('Unknown fixture purpose.'),
        };
        $added = match ($purpose) {
            'activation' => $this->activation->add($this->initial),
            'password_reset' => $this->reset->add($this->initial),
            'email_change' => $this->email->add($this->initial),
        };
        Assert::assertTrue($added);
    }

    public function current(): ActivationGrant|PasswordResetGrant|EmailChangeGrant
    {
        /** @var 'activation'|'password_reset'|'email_change' $purpose */
        $purpose = $this->purpose;
        $grant = match ($purpose) {
            'activation' => $this->activation->getLatestByUserId($this->user->getId()),
            'password_reset' => $this->reset->getLatestByUserId($this->user->getId()),
            'email_change' => $this->email->getLatestByUserId($this->user->getId()),
        };
        assert($grant !== null);

        return $grant;
    }

    public function replace(
        ActivationGrant|PasswordResetGrant|EmailChangeGrant $before,
        ActivationGrant|PasswordResetGrant|EmailChangeGrant $after
    ): bool {
        if ($before instanceof ActivationGrant && $after instanceof ActivationGrant) {
            return $this->activation->replace($before, $after);
        }

        if ($before instanceof PasswordResetGrant && $after instanceof PasswordResetGrant) {
            return $this->reset->replace($before, $after);
        }

        if ($before instanceof EmailChangeGrant && $after instanceof EmailChangeGrant) {
            return $this->email->replace($before, $after);
        }

        throw new LogicException('Mismatched fixture replacement.');
    }

    public function stage(string $state): ActivationGrant|PasswordResetGrant|EmailChangeGrant
    {
        $grant = $this->initial;
        if (in_array($state, ['claimed', 'retry', 'reclaimed', 'delivered', 'permanent', 'backoff_expired'], true)) {
            $token = CredentialDeliveryClaimToken::generate();
            $claimed = $grant->claimDelivery($token, $this->issuedAt, $this->issuedAt->modify('+30 seconds'));
            Assert::assertTrue($this->replace($grant, $claimed));
            $grant = $claimed;
            $next = match ($state) {
                'retry', 'reclaimed' => $claimed->failDelivery(
                    $token,
                    $this->issuedAt->modify('+1 second'),
                    CredentialDeliveryFailure::RETRYABLE_PROVIDER
                ),
                'delivered' => $claimed->confirmDelivery($token, $this->issuedAt->modify('+1 second')),
                'permanent' => $claimed->failDeliveryPermanently($token, $this->issuedAt->modify('+1 second')),
                default => $claimed,
            };
            if ($next !== $claimed) {
                Assert::assertTrue($this->replace($claimed, $next));
                $grant = $next;
            }

            if ($state === 'reclaimed') {
                $next = $grant->claimDelivery(
                    CredentialDeliveryClaimToken::generate(),
                    $this->issuedAt->modify('+2 minutes'),
                    $this->issuedAt->modify('+3 minutes')
                );
                Assert::assertTrue($this->replace($grant, $next));
                $grant = $next;
            }

            if ($state === 'backoff_expired') {
                $late = $grant->claimDelivery(
                    CredentialDeliveryClaimToken::generate(),
                    $this->expiresAt->modify('-10 seconds'),
                    $this->expiresAt
                );
                Assert::assertTrue($this->replace($grant, $late));
                $next = $late->failDelivery(
                    $late->getDelivery()->getClaimToken(),
                    $this->expiresAt->modify('-5 seconds'),
                    CredentialDeliveryFailure::RETRYABLE_PROVIDER
                );
                Assert::assertTrue($this->replace($late, $next));
                $grant = $next;
            }
        } else {
            $next = match ($state) {
                'pending' => $grant,
                'delivery_expired' => $grant->expireDeliveryAt($this->expiresAt),
                'consumed' => $grant->consume($this->issuedAt->modify('+1 second')),
                'revoked' => $grant->revoke($this->issuedAt->modify('+1 second')),
                'authority_expired' => $this->expireAuthority($grant),
                default => throw new LogicException('Unknown fixture state.'),
            };
            if ($next !== $grant) {
                Assert::assertTrue($this->replace($grant, $next));
                $grant = $next;
            }
        }

        return $grant;
    }

    public function handler(
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?EventDispatcher $events = null
    ): CommandHandler {
        $unitOfWork ??= $this->unitOfWork;
        $events ??= $this->events;

        /** @var 'activation'|'password_reset'|'email_change' $purpose */
        $purpose = $this->purpose;

        return match ($purpose) {
            'activation' => new ExpireInvitationDeliveryHandler($this->activation, $unitOfWork, $events),
            'password_reset' => new ExpirePasswordResetDeliveryHandler($this->reset, $unitOfWork, $events),
            'email_change' => new ExpireEmailChangeHandler($this->users, $this->email, $unitOfWork, $events),
        };
    }

    public function command(?DateTimeImmutable $at = null, string $actor = 'worker:expiry'): Command
    {
        $at ??= $this->expiresAt;

        /** @var 'activation'|'password_reset'|'email_change' $purpose */
        $purpose = $this->purpose;

        return match ($purpose) {
            'activation' => new ExpireInvitationDelivery(
                $actor,
                $this->user->getId(),
                ActivationDeliveryId::fromString($this->initial->getDelivery()->getId()->toString()),
                $at
            ),
            'password_reset' => new ExpirePasswordResetDelivery(
                $actor,
                $this->user->getId(),
                PasswordResetDeliveryId::fromString($this->initial->getDelivery()->getId()->toString()),
                $at
            ),
            'email_change' => new ExpireEmailChange($actor, $this->user->getId(), $this->email->all()[0]->getId(), $at),
        };
    }

    public function discovery(): FindExpiredCredentialDeliveriesHandler
    {
        return new FindExpiredCredentialDeliveriesHandler($this->activation, $this->reset, $this->email);
    }

    private function expireAuthority(
        ActivationGrant|PasswordResetGrant|EmailChangeGrant $grant
    ): EmailChangeGrant {
        Assert::assertInstanceOf(EmailChangeGrant::class, $grant);

        return $grant->expireAt($this->expiresAt);
    }
}
