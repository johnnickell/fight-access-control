<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSessionRevocation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\MessageId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditAction::class)]
#[CoversClass(AuditActorKind::class)]
#[CoversClass(AuditAgentOutcome::class)]
#[CoversClass(AuditAgentState::class)]
#[CoversClass(AuditGrantOutcome::class)]
#[CoversClass(AuditGrantState::class)]
#[CoversClass(AuditSessionRevocation::class)]
#[CoversClass(AuditUserOutcome::class)]
#[CoversClass(AuditUserRevisions::class)]
final class AuditEvidenceContractTest extends TestCase
{
    private function userRevisions(): AuditUserRevisions
    {
        return new AuditUserRevisions(UserState::ACTIVE, 3, 7, 2, 5, 1);
    }

    private function userEvidence(UserId|AgentId|SystemId|AnonymousId $actor): AuditEvidence
    {
        return AuditEvidence::record(
            $actor,
            new AuditSubject(UserId::generate()),
            AuditAction::PASSWORD_CHANGED,
            new DateTimeImmutable('2026-11-09T15:23:45.123456-04:00'),
            new AuditUserOutcome($this->userRevisions(), new AuditUserRevisions(UserState::ACTIVE, 4, 8, 2, 5, 1)),
            new AuditCorrelation(MessageId::generate(), null)
        );
    }

    public function test_actor_kind_identity_time_and_real_correlation_survive_hydration(): void
    {
        $user = UserId::generate();
        $actors = [$user, AgentId::fromString($user->toString()), SystemId::generate(), AnonymousId::generate()];
        $representations = [];
        foreach ($actors as $actor) {
            $evidence = $this->userEvidence($actor);
            $restored = AuditEvidence::fromArray($evidence->toArray());
            self::assertSame($evidence->toArray(), $restored->toArray());
            self::assertSame($actor::class, $restored->actorId()::class);
            self::assertSame($actor->toString(), $restored->actorId()->toString());
            self::assertSame($evidence->getId()->toString(), $restored->getId()->toString());
            self::assertSame(AuditAction::PASSWORD_CHANGED, $restored->action());
            self::assertSame($evidence->getSubject()->toArray(), $restored->getSubject()->toArray());
            self::assertEquals($evidence->getOccurredAt(), $restored->getOccurredAt());
            self::assertSame($evidence->getCorrelation()?->toArray(), $restored->getCorrelation()?->toArray());
            self::assertSame($evidence->getFacts()->toArray(), $restored->getFacts()->toArray());
            $representations[] = $restored->toArray()['actor'];
        }

        self::assertNotSame($representations[0], $representations[1]);
        self::assertNotSame($representations[2], $representations[3]);
        self::assertNotEquals($this->userEvidence($user)->getId(), $this->userEvidence($user)->getId());
    }

    public function test_creation_records_no_fabricated_predecessor_or_sensitive_user_state(): void
    {
        $at = new DateTimeImmutable('2026-11-09T15:23:45.123456Z');
        $user = User::invite(UserId::generate(), EmailAddress::fromString('person@example.test'), $at);
        $grant = ActivationGrant::issue(
            ActivationOrigin::REGISTRATION,
            $user->getId(),
            ActivationCredential::fromString('synthetic-activation-not-admitted'),
            $at,
            $at->modify('+7 days'),
            $user->getEmail(),
            'synthetic-ciphertext-not-admitted'
        );
        $facts = new AuditUserOutcome(
            null,
            AuditUserRevisions::fromUser($user),
            null,
            AuditGrantState::fromGrant($grant)
        );
        $evidence = AuditEvidence::record(
            AnonymousId::generate(),
            new AuditSubject($user->getId()),
            AuditAction::USER_REGISTERED,
            $at,
            $facts,
            null
        );
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        self::assertNull($facts->getBefore());
        self::assertNull($facts->getGrantBefore());
        self::assertSame($grant->getId(), $facts->getGrantAfter()?->getId());
        self::assertNull($evidence->getCorrelation());
        self::assertStringNotContainsString('person@', json_encode($evidence->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('ciphertext', json_encode($evidence->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_all_grant_families_have_exact_kind_preserving_revision_relationships(): void
    {
        $pairs = [
            [ActivationGrantId::generate(), ActivationDeliveryId::generate(), AuditAction::INVITATION_RESENT],
            [
                PasswordResetGrantId::generate(), PasswordResetDeliveryId::generate(),
                AuditAction::PASSWORD_RESET_REQUESTED
            ],
            [
                EmailChangeGrantId::generate(), EmailChangeDeliveryId::generate(),
                AuditAction::EMAIL_CHANGE_DELIVERY_FAILED
            ]
        ];
        foreach ($pairs as [$id, $deliveryId, $action]) {
            $before = new AuditGrantState($id, 4, $deliveryId);
            $after = new AuditGrantState($id, 5, $deliveryId);
            $outcome = null;
            if ($action === AuditAction::EMAIL_CHANGE_DELIVERY_FAILED) {
                $outcome = CredentialDeliveryStatus::PERMANENT_FAILURE;
            }

            $facts = new AuditGrantOutcome($before, $after, $outcome);
            self::assertSame($before, $facts->getBefore());
            self::assertSame($after, $facts->getAfter());
            $evidence = AuditEvidence::record(
                UserId::generate(),
                new AuditSubject(UserId::generate()),
                $action,
                new DateTimeImmutable(),
                $facts,
                new AuditCorrelation(MessageId::generate(), null)
            );
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        }
    }

    public function test_credential_creation_rotation_and_revocation_preserve_actual_states(): void
    {
        $credential = AgentCredentialId::generate();
        $agentId = AgentId::generate();
        $active = new AuditAgentState($credential, 2, AgentState::ACTIVE);
        $successor = new AuditAgentState(AgentCredentialId::generate(), 3, AgentState::ACTIVE);
        $key = new AgentOperationKey(
            new AgentOperationScope('audit-test', 'user', 'origin'),
            AgentOperationId::generate()
        );
        $correlation = new AuditCorrelation(MessageId::generate(), $key);
        $cases = [
            [AuditAction::AGENT_PROVISIONED, new AuditAgentOutcome(null, $active, AgentDeliveryId::generate())],
            [
                AuditAction::AGENT_CREDENTIAL_ROTATED,
                new AuditAgentOutcome($active, $successor, AgentDeliveryId::generate())
            ],
            [AuditAction::AGENT_CREDENTIAL_REVOKED, new AuditAgentOutcome(
                $active,
                new AuditAgentState($credential, 2, AgentState::REVOKED),
                null
            )]
        ];
        foreach ($cases as [$action, $facts]) {
            $evidence = AuditEvidence::record(
                SystemId::generate(),
                new AuditSubject($agentId),
                $action,
                new DateTimeImmutable(),
                $facts,
                $correlation
            );
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        }
    }

    public function test_session_evidence_admits_no_free_text_reason(): void
    {
        $facts = new AuditSessionRevocation(RefreshSessionId::generate(), 4, 5);
        $evidence = AuditEvidence::record(
            UserId::generate(),
            new AuditSubject(UserId::generate()),
            AuditAction::SESSION_ADMINISTRATIVELY_REVOKED,
            new DateTimeImmutable(),
            $facts,
            new AuditCorrelation(MessageId::generate(), null)
        );
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        self::assertSame(['session_id', 'before', 'after'], array_keys($facts->toArray()));
    }

    public function test_role_creation_change_and_removal_keep_complete_incarnation(): void
    {
        $revision = RoleRevision::initial();
        foreach (
            [
                [AuditAction::ROLE_CREATED, new AuditRoleRevisions(null, $revision)],
                [AuditAction::ROLE_RENAMED, new AuditRoleRevisions($revision, $revision->next())],
                [AuditAction::ROLE_REMOVED, new AuditRoleRevisions($revision, null)]
            ] as [$action, $facts]
        ) {
            $evidence = AuditEvidence::record(
                UserId::generate(),
                new AuditSubject(RoleId::generate()),
                $action,
                new DateTimeImmutable(),
                $facts,
                new AuditCorrelation(MessageId::generate(), null)
            );
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        }
    }

    /** @phpstan-return iterable<string, array{string, mixed}> */
    public static function invalidEvidenceFields(): iterable
    {
        yield 'unadmitted raw field' => ['payload', 'synthetic-secret'];
        yield 'unknown action' => ['action', 'consumer.arbitrary'];
        yield 'string actor' => ['actor', 'worker-label'];
        yield 'missing actor kind' => ['actor', ['id' => UserId::generate()->toString()]];
        yield 'unknown actor kind' => ['actor', ['kind' => 'service', 'id' => UserId::generate()->toString()]];
        yield 'extra actor field' => ['actor', [
            'kind' => 'user',
            'id'   => UserId::generate()->toString(),
            'raw'  => 'x'
        ]];
        yield 'non-nil system' => ['actor', ['kind' => 'system', 'id' => UserId::generate()->toString()]];
        yield 'unbounded facts' => ['facts', ['reason' => 'synthetic-secret']];
        yield 'unbounded correlation' => ['correlation', ['message' => ['raw' => 'synthetic-secret']]];
        yield 'coercive time' => ['occurred_at', 123];
        yield 'imprecise time' => ['occurred_at', '2026-11-09T15:23:45-04:00'];
    }

    #[DataProvider('invalidEvidenceFields')]
    public function test_invalid_evidence_never_falls_back_to_legacy_fields(string $field, mixed $value): void
    {
        $data = $this->userEvidence(UserId::generate())->toArray();
        $data[$field] = $value;
        $this->expectException(DomainException::class);
        AuditEvidence::fromArray($data);
    }

    public function test_missing_correlation_field_is_not_inferred_from_evidence_identity(): void
    {
        $data = $this->userEvidence(UserId::generate())->toArray();
        unset($data['correlation']);
        $this->expectException(DomainException::class);
        AuditEvidence::fromArray($data);
    }

    /** @phpstan-return iterable<string, array{Closure(): mixed}> */
    public static function invalidFacts(): iterable
    {
        yield 'User native counters' => [static fn (): AuditUserRevisions => AuditUserRevisions::fromArray([
            'state'                    => 'active',
            'authentication_version'   => '1',
            'authentication_authority' => 0,
            'authorization_assignment' => 0,
            'email_reservation'        => 0,
            'canonical_email'          => 0
        ])];
        yield 'User incomplete' => [static fn (): AuditUserRevisions => AuditUserRevisions::fromArray([])];
        yield 'User invalid version' => [static fn (): AuditUserRevisions => new AuditUserRevisions(
            UserState::ACTIVE,
            0,
            0,
            0,
            0,
            0
        )];
        yield 'User facts missing' => [static fn (): AuditUserOutcome => AuditUserOutcome::fromArray([])];
        yield 'User invalid predecessor' => [static fn (): AuditUserOutcome => AuditUserOutcome::fromArray([
            'before'       => 'missing',
            'after'        => [],
            'grant_before' => null,
            'grant_after'  => null
        ])];
        yield 'Agent revision negative' => [static fn (): AuditAgentState => new AuditAgentState(
            AgentCredentialId::generate(),
            -1,
            AgentState::ACTIVE
        )];
        yield 'Agent state missing' => [static fn (): AuditAgentState => AuditAgentState::fromArray([])];
        yield 'Agent facts missing' => [static fn (): AuditAgentOutcome => AuditAgentOutcome::fromArray([])];
        yield 'grant native revision' => [static fn (): AuditGrantState => AuditGrantState::fromArray([
            'kind'        => 'activation',
            'id'          => ActivationGrantId::generate()->toString(),
            'revision'    => '1',
            'delivery_id' => ActivationDeliveryId::generate()->toString()
        ])];
        yield 'grant unknown kind' => [static fn (): AuditGrantState => AuditGrantState::fromArray([
            'kind'        => 'arbitrary',
            'id'          => ActivationGrantId::generate()->toString(),
            'revision'    => 1,
            'delivery_id' => ActivationDeliveryId::generate()->toString()
        ])];
        yield 'grant wrong delivery family' => [static fn (): AuditGrantState => new AuditGrantState(
            ActivationGrantId::generate(),
            1,
            PasswordResetDeliveryId::generate()
        )];
        yield 'grant negative revision' => [static fn (): AuditGrantState => new AuditGrantState(
            ActivationGrantId::generate(),
            -1,
            ActivationDeliveryId::generate()
        )];
        yield 'grant outcome incomplete' => [static fn (): AuditGrantOutcome => AuditGrantOutcome::fromArray([])];
        yield 'failure contradicts delivered state' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            null,
            new AuditGrantState(ActivationGrantId::generate(), 0, ActivationDeliveryId::generate()),
            CredentialDeliveryStatus::DELIVERED,
            CredentialDeliveryFailure::UNEXPECTED_PROVIDER
        )];
        yield 'session facts incomplete' => [static fn (): AuditSessionRevocation => AuditSessionRevocation::fromArray(
            []
        )];
        yield 'session revision overflow' => [static fn (): AuditSessionRevocation => new AuditSessionRevocation(
            RefreshSessionId::generate(),
            PHP_INT_MAX,
            PHP_INT_MAX
        )];
        yield 'session revision skipped' => [static fn (): AuditSessionRevocation => new AuditSessionRevocation(
            RefreshSessionId::generate(),
            1,
            3
        )];
    }

    #[DataProvider('invalidFacts')]
    public function test_unadmitted_or_invalid_native_facts_are_rejected(Closure $construct): void
    {
        $this->expectException(DomainException::class);
        $construct();
    }

    public function test_actions_reject_unrelated_facts_and_invented_predecessors(): void
    {
        $grant = new AuditGrantState(ActivationGrantId::generate(), 1, ActivationDeliveryId::generate());
        $reset = new AuditGrantState(PasswordResetGrantId::generate(), 1, PasswordResetDeliveryId::generate());
        $cases = [
            [
                AuditAction::USER_INVITED,
                new AuditUserOutcome($this->userRevisions(), $this->userRevisions(), null, $grant)
            ],
            [AuditAction::USER_REGISTERED, new AuditUserOutcome(null, $this->userRevisions())],
            [AuditAction::USER_PENDING_INVITATION_CORRECTED, new AuditUserOutcome(
                $this->userRevisions(),
                $this->userRevisions(),
                $reset,
                $reset
            )],
            [AuditAction::ROLE_RENAMED, new AuditUserOutcome($this->userRevisions(), $this->userRevisions())],
            [AuditAction::INVITATION_RESENT, new AuditGrantOutcome(null, $grant)],
            [AuditAction::INVITATION_DELIVERY_CONFIRMED, new AuditGrantOutcome($reset, $reset)],
            [AuditAction::ROLE_REMOVED, new AuditGrantOutcome($grant, $grant)],
            [AuditAction::INVITATION_DELIVERY_CONFIRMED, new AuditGrantOutcome($grant, $grant)]
        ];
        foreach ($cases as [$action, $facts]) {
            try {
                AuditEvidence::record(
                    UserId::generate(),
                    new AuditSubject(UserId::generate()),
                    $action,
                    new DateTimeImmutable(),
                    $facts,
                    null
                );
                self::fail('Unrelated facts or an invented predecessor was accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_role_or_credential_transitions_cannot_become_evidence(): void
    {
        $revision = RoleRevision::initial();
        $agentState = new AuditAgentState(AgentCredentialId::generate(), 0, AgentState::ACTIVE);
        $cases = [
            [new AuditSubject(RoleId::generate()), AuditAction::ROLE_CREATED,
                new AuditRoleRevisions($revision, $revision->next())],
            [new AuditSubject(RoleId::generate()), AuditAction::PASSWORD_CHANGED,
                new AuditRoleRevisions($revision, $revision->next())],
            [new AuditSubject(AgentId::generate()), AuditAction::AGENT_PROVISIONED,
                new AuditAgentOutcome($agentState, $agentState, AgentDeliveryId::generate())],
            [new AuditSubject(AgentId::generate()), AuditAction::PASSWORD_CHANGED,
                new AuditAgentOutcome(null, $agentState, AgentDeliveryId::generate())]
        ];
        foreach ($cases as [$subject, $action, $facts]) {
            try {
                AuditEvidence::record(UserId::generate(), $subject, $action, new DateTimeImmutable(), $facts, null);
                self::fail('Invalid state transition was admitted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_issuance_without_the_real_scoped_key_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AuditEvidence::record(
            UserId::generate(),
            new AuditSubject(AgentId::generate()),
            AuditAction::AGENT_PROVISIONED,
            new DateTimeImmutable(),
            new AuditAgentOutcome(
                null,
                new AuditAgentState(AgentCredentialId::generate(), 0, AgentState::ACTIVE),
                AgentDeliveryId::generate()
            ),
            new AuditCorrelation(MessageId::generate(), null)
        );
    }

    public function test_subject_fact_mismatch_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        AuditEvidence::record(
            UserId::generate(),
            new AuditSubject(AgentId::generate()),
            AuditAction::PASSWORD_CHANGED,
            new DateTimeImmutable(),
            new AuditUserOutcome($this->userRevisions(), $this->userRevisions()),
            null
        );
    }
}
