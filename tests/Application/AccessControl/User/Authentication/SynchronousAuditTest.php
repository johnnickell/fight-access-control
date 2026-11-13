<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Authentication;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler\RevokeSessionHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAuthenticationOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedSessionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\TestPasswordSecurity;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(UserAuthentication::class)]
#[CoversClass(SessionLifecycle::class)]
#[CoversClass(PasswordManagement::class)]
#[CoversClass(EmailChangeConfirmation::class)]
#[CoversClass(RevokeSessionHandler::class)]
#[CoversClass(SessionRevocationService::class)]
#[CoversClass(AuditAuthenticationOutcome::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditUserRevisions::class)]
final class SynchronousAuditTest extends TestCase
{
    use SynchronousAuthenticationFixture;

    public static function outcomes(): iterable
    {
        foreach (
            [
            'activate' => AuditAction::USER_ACTIVATED,
            'login'    => AuditAction::USER_LOGGED_IN,
            'refresh'  => AuditAction::SESSION_REFRESHED,
            'replay'   => AuditAction::SESSION_REPLAY_REVOKED,
            'logout'   => AuditAction::SESSION_LOGGED_OUT,
            'change'   => AuditAction::PASSWORD_CHANGED,
            'reset'    => AuditAction::PASSWORD_RESET_COMPLETED,
            'confirm'  => AuditAction::EMAIL_CHANGE_CONFIRMED,
            'self'     => AuditAction::SESSION_REVOKED,
            'admin'    => AuditAction::SESSION_ADMINISTRATIVELY_REVOKED
            ] as $operation => $action
        ) {
            yield $operation => [$operation, $action];
        }
    }

    public static function operations(): iterable
    {
        foreach (self::outcomes() as $name => $case) {
            yield $name => [$case[0]];
        }
    }

    #[DataProvider('outcomes')]
    public function test_enabled_outcomes_are_typed_secret_free_and_committed_once(
        string $operation,
        AuditAction $action
    ): void {
        $fixture = $this->arrange($operation);
        $before = ($fixture['snapshot'])();

        $failure = $this->execute($fixture);

        self::assertSame($operation === 'replay', $failure instanceof AuthenticationRequired);
        self::assertNotEquals($before, ($fixture['snapshot'])());
        self::assertTrue($fixture['unitOfWork']->transactionCompleted);
        self::assertCount(1, $fixture['audit']->all());
        $evidence = $fixture['audit']->all()[0];
        self::assertSame($action, $evidence->action());
        $after = ($fixture['snapshot'])();
        $facts = $evidence->getFacts()->toArray();
        if (in_array($operation, ['activate', 'login', 'refresh'], true)) {
            self::assertSame(AuditUserRevisions::fromUser($before[0])->toArray(), $facts['user']['before']);
            self::assertSame(AuditUserRevisions::fromUser($after[0])->toArray(), $facts['user']['after']);
            $session = $after[4][array_key_last($after[4])];
            self::assertSame($session->getId()->toString(), $facts['session_id']);
            self::assertSame($session->getRevision(), $facts['session_after']);
            self::assertSame($operation === 'refresh' ? 0 : null, $facts['session_before']);
        } elseif (in_array($operation, ['change', 'reset', 'confirm'], true)) {
            self::assertSame(AuditUserRevisions::fromUser($before[0])->toArray(), $facts['before']);
            self::assertSame(AuditUserRevisions::fromUser($after[0])->toArray(), $facts['after']);
        } else {
            self::assertSame($before[4][0]->getId()->toString(), $facts['session_id']);
            self::assertSame($before[4][0]->getRevision(), $facts['before']);
            self::assertSame($after[4][0]->getRevision(), $facts['after']);
            self::assertTrue($after[4][0]->isRevoked());
        }

        self::assertEquals($fixture['userId'], $evidence->getSubject()->getId());
        $expectedActor = AnonymousId::generate();
        if (in_array($operation, ['login', 'refresh', 'change', 'self', 'admin'], true)) {
            $expectedActor = $fixture['actorId'];
        }

        self::assertEquals($expectedActor, $evidence->actorId());
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:00:00Z'), $evidence->getOccurredAt());
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        self::assertSame(in_array($operation, ['self', 'admin'], true), $evidence->getCorrelation() !== null);
        foreach (
            ['correct-secret', 'new-password', 'ciphertext:', 'new@example.test', 'Secret reason omitted',
            self::REFRESH_CREDENTIAL,
            self::ACTIVATION_CREDENTIAL, self::RESET_CREDENTIAL, self::EMAIL_CHANGE_CREDENTIAL] as $secret
        ) {
            self::assertStringNotContainsString($secret, serialize($evidence->toArray()));
        }
    }

    #[DataProvider('operations')]
    public function test_disabled_outcomes_require_no_new_audit_provenance(string $operation): void
    {
        $fixture = $this->arrange($operation, enabled: false, provenance: false);
        $before = ($fixture['snapshot'])();

        $failure = $this->execute($fixture);

        self::assertSame($operation === 'replay', $failure instanceof AuthenticationRequired);
        self::assertNotEquals($before, ($fixture['snapshot'])());
        self::assertSame([], $fixture['audit']->all());
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_all_coupled_persisted_state(string $operation): void
    {
        $fixture = $this->arrange($operation, auditFails: true);
        $before = ($fixture['snapshot'])();

        $failure = $this->execute($fixture);

        self::assertSame($fixture['audit']->failure(), $failure);
        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertSame([], $fixture['audit']->all());
        self::assertFalse($fixture['unitOfWork']->transactionCompleted);
    }

    #[DataProvider('operations')]
    public function test_lost_commit_acknowledgement_keeps_the_real_committed_outcome(string $operation): void
    {
        $fixture = $this->arrange($operation);
        $before = ($fixture['snapshot'])();
        $uncertain = new RuntimeException('Lost commit acknowledgement.');
        $fixture['unitOfWork']->afterCommit = static fn(): never => throw $uncertain;

        $failure = $this->execute($fixture);

        self::assertSame($uncertain, $failure);
        self::assertNotEquals($before, ($fixture['snapshot'])());
        self::assertCount(1, $fixture['audit']->all());
    }

    #[DataProvider('operations')]
    public function test_precommit_failure_retains_neither_mutation_nor_evidence(string $operation): void
    {
        $fixture = $this->arrange($operation);
        $before = ($fixture['snapshot'])();
        $primary = new RuntimeException('Commit failed before persistence.');
        $fixture['unitOfWork']->beforeCommit = static fn(): never => throw $primary;

        self::assertSame($primary, $this->execute($fixture));
        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertSame([], $fixture['audit']->all());
    }

    public static function publicationOperations(): iterable
    {
        foreach (self::operations() as $name => $case) {
            if (!in_array($name, ['refresh', 'replay'], true)) {
                yield $name => $case;
            }
        }
    }

    #[DataProvider('publicationOperations')]
    public function test_both_publishers_failing_preserves_committed_evidence_and_primary_fault(string $operation): void
    {
        $primary = new RuntimeException('Success subscriber failed.');
        $secondary = new RuntimeException('Failure subscriber failed.');
        $calls = 0;
        $events = new InMemoryEventDispatcher(static function () use (&$calls, $primary, $secondary): void {
            throw ++$calls === 1 ? $primary : $secondary;
        });
        $fixture = $this->arrange($operation, events: $events);
        $before = ($fixture['snapshot'])();

        self::assertSame($primary, $this->execute($fixture));
        self::assertSame(2, $calls);
        self::assertNotEquals($before, ($fixture['snapshot'])());
        self::assertCount(1, $fixture['audit']->all());
    }

    public static function unattributedOperations(): iterable
    {
        foreach (['activate', 'replay', 'logout', 'reset', 'confirm'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('unattributedOperations')]
    public function test_missing_enabled_provenance_rejects_before_mutation(string $operation): void
    {
        $fixture = $this->arrange($operation, provenance: false);
        $before = ($fixture['snapshot'])();

        self::assertInstanceOf(AuthenticationRequired::class, $this->execute($fixture));
        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertSame([], $fixture['audit']->all());
    }

    #[DataProvider('operations')]
    public function test_rejected_work_creates_no_mutation_evidence_or_audit_input_obligation(string $operation): void
    {
        $fixture = $this->arrange($operation, provenance: false, rejectInput: true);
        $before = ($fixture['snapshot'])();

        self::assertNotNull($this->execute($fixture));
        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertSame([], $fixture['audit']->all());
    }

    public function test_benign_conflict_needs_no_provenance_and_creates_no_additional_evidence(): void
    {
        $fixture = $this->arrange('refresh');
        ($fixture['execute'])();
        $before = ($fixture['snapshot'])();

        $result = $fixture['lifecycle']->refresh(self::REFRESH_CREDENTIAL);

        self::assertNull($result->getTokenSet());
        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertCount(1, $fixture['audit']->all());
    }

    public function test_repeated_logout_is_a_no_change_without_audit_only_provenance(): void
    {
        $fixture = $this->arrange('logout');
        ($fixture['execute'])();
        $before = ($fixture['snapshot'])();

        $fixture['lifecycle']->logout(self::REFRESH_CREDENTIAL);

        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertCount(1, $fixture['audit']->all());
    }

    public static function revocationOperations(): iterable
    {
        yield 'logout' => ['logout'];
        yield 'terminal replay' => ['replay'];
    }

    #[DataProvider('revocationOperations')]
    public function test_concurrent_winner_revocation_is_not_recorded_again(string $operation): void
    {
        $fixture = null;
        $winner = null;
        $fixture = $this->arrange($operation, beforeSessionReplace: static function () use (&$fixture, &$winner): void {
            if ($winner === null) {
                $winner = $fixture['sessionState']->refreshSessions[0]->revoke();
                $fixture['sessionState']->refreshSessions[0] = $winner;
            }
        });

        $failure = $this->execute($fixture);

        self::assertSame($operation === 'replay', $failure instanceof AuthenticationRequired);
        self::assertSame($winner, $fixture['sessionState']->refreshSessions[0]);
        self::assertSame([], $fixture['audit']->all());
    }

    public static function authorityOperations(): iterable
    {
        foreach (['activate', 'login', 'change', 'reset', 'confirm'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('authorityOperations')]
    public function test_concurrent_authority_loss_rolls_back_grant_and_session_work(string $operation): void
    {
        $fixture = $this->arrange($operation, loseAuthority: true);
        $before = ($fixture['snapshot'])();

        self::assertNotNull($this->execute($fixture));
        self::assertEquals($before, ($fixture['snapshot'])());
        self::assertSame([], $fixture['audit']->all());
    }

    private function execute(array $fixture): ?Throwable
    {
        try {
            ($fixture['execute'])();

            return null;
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }

    private function arrange(
        string $operation,
        bool $enabled = true,
        bool $provenance = true,
        bool $auditFails = false,
        ?InMemoryEventDispatcher $events = null,
        ?Closure $beforeSessionReplace = null,
        bool $loseAuthority = false,
        bool $rejectInput = false
    ): array {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository(
            $unitOfWork,
            replaceAuthenticationAuthoritySucceeds: !$loseAuthority,
            replaceEmailChangeConfirmationSucceeds: !$loseAuthority
        );
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $refreshSessionRepository = new InMemoryRefreshSessionRepository(
            $unitOfWork,
            $sessionState,
            $beforeSessionReplace
        );
        $userRepository->bindRefreshSessionRepository($refreshSessionRepository);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: $auditFails);
        $repository = $enabled ? $audit : null;
        $events ??= new InMemoryEventDispatcher();
        $now = new DateTimeImmutable('2026-08-19T12:00:00Z');
        $clock = new FixedClock($now);
        $user = $this->activeUserFor('audit@example.test');
        if ($operation === 'activate') {
            $user = User::invite($user->getId(), $user->getEmail(), $now);
            $activationGrantRepository->add($this->grant($user->getId()));
        }

        if ($operation === 'confirm') {
            $user->requestEmailChange(EmailAddress::fromString('new@example.test'), $now);
            $emailChangeGrantRepository->add($this->emailChangeGrant($user->getId()));
        }

        $userRepository->add($user);
        if ($operation !== 'activate') {
            $passwordResetGrantRepository->add(PasswordResetGrant::issue(
                $user->getId(),
                PasswordResetCredential::fromString(self::RESET_CREDENTIAL),
                $now->modify('-1 hour'),
                $now->modify('+1 hour'),
                $user->getEmail(),
                'ciphertext:reset-once',
                $user->getRecoveryAuthority()
            ));
        }

        $session = $this->session($user, $this->refreshCredential(), false);
        if ($operation === 'replay') {
            $session = $session->rotate(
                RefreshCredential::fromString(str_repeat('b', 64)),
                $now->modify('-6 seconds'),
                $now->modify('+1 day')
            );
        }

        if ($operation !== 'activate') {
            $refreshSessionRepository->add($session);
        }

        $actorId = $operation === 'admin' ? UserId::generate() : $user->getId();
        $origin = $provenance ? AnonymousId::generate() : null;
        $passwords = new TestPasswordSecurity();
        $tokenPolicy = AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'));
        $tokenIssuer = new TokenIssuer(new RecordingTokenEncoder(), $tokenPolicy);
        $authentication = new UserAuthentication(
            $userRepository,
            $activationGrantRepository,
            $unitOfWork,
            $clock,
            new FixedLoginThrottle(true),
            new FixedRefreshCredentialGenerator($this->refreshCredential()),
            $passwords,
            $passwords,
            $tokenIssuer,
            PasswordHash::fromString(password_hash('dummy', PASSWORD_DEFAULT)),
            $events,
            $repository
        );
        $lifecycle = new SessionLifecycle(
            $userRepository,
            $refreshSessionRepository,
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $clock,
            new FixedRefreshCredentialGenerator(RefreshCredential::fromString(str_repeat('c', 64))),
            $tokenIssuer,
            $tokenPolicy,
            $events,
            $repository
        );
        $passwordManagement = new PasswordManagement(
            $userRepository,
            $passwordResetGrantRepository,
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $clock,
            $passwords,
            $passwords,
            PasswordHash::fromString(password_hash('dummy', PASSWORD_DEFAULT)),
            $events,
            $repository
        );
        $confirmation = new EmailChangeConfirmation(
            $userRepository,
            $emailChangeGrantRepository,
            $passwordResetGrantRepository,
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $clock,
            $events,
            $repository
        );
        $handler = new RevokeSessionHandler(
            $refreshSessionRepository,
            $clock,
            new FixedSessionAdministrationAuthorization(true),
            $unitOfWork,
            $events,
            $repository
        );
        $userId = $user->getId();
        $activationCredential = $rejectInput ? 'invalid' : self::ACTIVATION_CREDENTIAL;
        $refreshCredential = $rejectInput ? 'invalid' : self::REFRESH_CREDENTIAL;
        $resetCredential = $rejectInput ? 'invalid' : self::RESET_CREDENTIAL;
        $emailCredential = $rejectInput ? 'invalid' : self::EMAIL_CHANGE_CREDENTIAL;
        $currentPassword = $rejectInput ? 'incorrect' : 'correct-secret';
        $currentSessionId = $rejectInput ? $session->getId() : RefreshSessionId::generate();
        $execute = match ($operation) {
            'activate' => fn(): TokenSet => $authentication->activate(
                $userId,
                $activationCredential, 'new-password', false, $origin
            ),
            'login' => fn(): TokenSet => $authentication->login('audit@example.test', $currentPassword),
            'refresh', 'replay' => fn(): RefreshResult => $lifecycle->refresh($refreshCredential, $origin),
            'logout' => fn() => $lifecycle->logout($refreshCredential, $origin),
            'change' => fn() => $passwordManagement->changePassword($userId, $currentPassword, 'new-password'),
            'reset' => fn() => $passwordManagement->resetPassword(
                $userId,
                $resetCredential, 'new-password', $origin
            ),
            'confirm' => fn() => $confirmation->confirmEmail($userId, $emailCredential, $origin),
            'self', 'admin' => fn() => $handler->handle(CommandMessage::create(new RevokeSession(
                $actorId,
                $currentSessionId,
                $session->getId(),
                $operation === 'admin' ? SessionRevocationReason::fromString('Secret reason omitted') : null
            )))
        };
        $snapshot = fn(): array => [
            $userRepository->getById($userId),
            $activationGrantRepository->getLatestByUserId($userId),
            $passwordResetGrantRepository->getLatestByUserId($userId),
            $emailChangeGrantRepository->getLatestByUserId($userId),
            $refreshSessionRepository->all()
        ];

        return compact('unitOfWork', 'audit', 'execute', 'snapshot', 'userId', 'actorId', 'lifecycle', 'sessionState');
    }
}
