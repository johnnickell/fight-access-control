<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\MessageId;
use Fight\Common\Domain\Value\Identifier\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditCorrelation::class)]
#[CoversClass(AuditEvidenceId::class)]
#[CoversClass(AuditRoleRevisions::class)]
#[CoversClass(AuditSubject::class)]
final class AuditEvidenceValuesTest extends TestCase
{
    public function test_evidence_ids_are_unique_non_nil_and_round_trip_without_regeneration(): void
    {
        $first = AuditEvidenceId::generate();
        $second = AuditEvidenceId::generate();

        self::assertNotSame(Uuid::NIL, $first->toString());
        self::assertFalse($first->equals($second));
        self::assertTrue($first->equals(AuditEvidenceId::fromString($first->toString())));
    }

    public function test_evidence_id_rejects_nil_instead_of_reusing_a_special_actor_identity(): void
    {
        $this->expectException(DomainException::class);
        AuditEvidenceId::fromString(Uuid::NIL);
    }

    public function test_evidence_id_rejects_malformed_uuid(): void
    {
        $this->expectException(DomainException::class);
        AuditEvidenceId::fromString('not-an-identity');
    }

    public function test_subjects_preserve_kind_even_when_all_uuid_text_is_equal(): void
    {
        $uuid = UserId::generate()->toString();
        $identities = [
            'user'       => UserId::fromString($uuid),
            'agent'      => AgentId::fromString($uuid),
            'role'       => RoleId::fromString($uuid),
            'permission' => PermissionId::fromString($uuid),
            'feature'    => FeatureId::fromString($uuid)
        ];
        foreach ($identities as $kind => $identity) {
            $subject = new AuditSubject($identity);
            self::assertSame($identity, $subject->getId());
            self::assertSame(['kind' => $kind, 'id' => $uuid], $subject->toArray());
            $hydrated = AuditSubject::fromArray($subject->toArray());
            self::assertTrue($identity->equals($hydrated->getId()));
            self::assertSame($subject->toArray(), $hydrated->toArray());
        }

        self::assertFalse($identities['user']->equals($identities['agent']));
    }

    /** @phpstan-return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidSubjects(): iterable
    {
        $id = UserId::generate()->toString();
        yield 'missing kind' => [['id' => $id]];
        yield 'missing identity' => [['kind' => 'user']];
        yield 'numeric list' => [['user', $id]];
        yield 'null kind' => [['kind' => null, 'id' => $id]];
        yield 'non string identity' => [['kind' => 'user', 'id' => UserId::generate()]];
        yield 'unsupported kind' => [['kind' => 'unrestricted_entity', 'id' => $id]];
        yield 'actor is not a primary subject' => [['kind' => 'anonymous', 'id' => Uuid::NIL]];
        yield 'no payload capture' => [['kind' => 'user', 'id' => $id, 'payload' => 'excluded']];
        yield 'malformed identity' => [['kind' => 'role', 'id' => 'role-label']];
    }

    /** @phpstan-param array<array-key, mixed> $data */
    #[DataProvider('invalidSubjects')]
    public function test_subject_hydration_rejects_unsupported_or_incomplete_data(array $data): void
    {
        $this->expectException(DomainException::class);
        AuditSubject::fromArray($data);
    }

    public function test_correlation_retains_both_incoming_message_and_complete_operation_scope(): void
    {
        $messageId = MessageId::generate();
        $operationId = AgentOperationId::generate();
        $firstKey = new AgentOperationKey(new AgentOperationScope('consumer', 'user', 'origin-a'), $operationId);
        $secondKey = new AgentOperationKey(new AgentOperationScope('consumer', 'user', 'origin-b'), $operationId);
        $correlation = new AuditCorrelation($messageId, $firstKey);

        self::assertSame($messageId, $correlation->getMessageId());
        self::assertSame($firstKey, $correlation->getOperationKey());
        self::assertSame([
            'message_id'    => $messageId->toString(),
            'operation_key' => $firstKey->toArray()
        ], $correlation->toArray());
        $hydrated = AuditCorrelation::fromArray($correlation->toArray());
        self::assertSame($correlation->toArray(), $hydrated->toArray());
        self::assertSame($firstKey->toString(), $hydrated->getOperationKey()?->toString());
        self::assertTrue($messageId->equals($hydrated->getMessageId()));
        self::assertNotSame(
            $correlation->toArray(),
            new AuditCorrelation($messageId, $secondKey)->toArray()
        );
    }

    public function test_message_only_and_operation_only_correlation_do_not_invent_missing_data(): void
    {
        $messageId = MessageId::generate();
        $key = new AgentOperationKey(
            new AgentOperationScope('consumer', 'agent', 'original-agent'),
            AgentOperationId::generate()
        );
        foreach ([new AuditCorrelation($messageId, null), new AuditCorrelation(null, $key)] as $correlation) {
            $hydrated = AuditCorrelation::fromArray($correlation->toArray());
            self::assertSame($correlation->toArray(), $hydrated->toArray());
            self::assertSame($correlation->getMessageId() === null, $hydrated->getMessageId() === null);
            self::assertSame($correlation->getOperationKey() === null, $hydrated->getOperationKey() === null);
        }
    }

    public function test_empty_correlation_must_be_absent_not_fabricated(): void
    {
        $this->expectException(DomainException::class);
        new AuditCorrelation(null, null);
    }

    /** @phpstan-return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidCorrelations(): iterable
    {
        $message = MessageId::generate()->toString();
        $key = new AgentOperationKey(
            new AgentOperationScope('consumer', 'user', 'origin'),
            AgentOperationId::generate()
        )->toArray();
        yield 'missing message field' => [['operation_key' => null]];
        yield 'missing operation field' => [['message_id' => $message]];
        yield 'message is not a string' => [['message_id' => 123, 'operation_key' => null]];
        yield 'operation is not an array' => [['message_id' => $message, 'operation_key' => 'label']];
        yield 'raw message is not admitted' => [['message_id' => $message, 'operation_key' => null, 'message' => []]];
        yield 'both absent' => [['message_id' => null, 'operation_key' => null]];
        yield 'incomplete scope' => [[
            'message_id' => null, 'operation_key' => ['operation_id' => $key['operation_id']]
        ]];
        yield 'wrong scope types' => [[
            'message_id' => null, 'operation_key' => array_replace($key, ['caller_id' => 1])
        ]];
        yield 'unknown scoped key fields' => [[
            'message_id' => null, 'operation_key' => $key + ['payload' => 'excluded']
        ]];
        yield 'malformed message identity' => [['message_id' => 'message-label', 'operation_key' => null]];
    }

    /** @phpstan-param array<array-key, mixed> $data */
    #[DataProvider('invalidCorrelations')]
    public function test_correlation_hydration_does_not_accept_partial_keys_or_payloads(array $data): void
    {
        $this->expectException(DomainException::class);
        AuditCorrelation::fromArray($data);
    }

    public function test_role_creation_change_and_removal_preserve_complete_authoritative_revisions(): void
    {
        $initial = RoleRevision::initial();
        $next = $initial->next();
        $creation = new AuditRoleRevisions(null, $initial);
        $change = new AuditRoleRevisions($initial, $next);
        $removal = new AuditRoleRevisions($next, null);

        self::assertNull($creation->getBefore());
        self::assertSame($initial, $creation->getAfter());
        self::assertSame($initial, $change->getBefore());
        self::assertSame($next, $change->getAfter());
        self::assertNull($removal->getAfter());
        foreach ([$creation, $change, $removal] as $revisions) {
            self::assertSame($revisions->toArray(), AuditRoleRevisions::fromArray($revisions->toArray())->toArray());
        }
    }

    /** @phpstan-return iterable<string, array{?RoleRevision, ?RoleRevision}> */
    public static function invalidRoleTransitions(): iterable
    {
        $initial = RoleRevision::initial();
        yield 'neither state exists' => [null, null];
        yield 'no change' => [$initial, $initial];
        yield 'wrong incarnation' => [$initial, RoleRevision::initial()->next()];
        yield 'skipped revision' => [$initial, $initial->next()->next()];
        yield 'backward revision' => [$initial->next(), $initial];
    }

    #[DataProvider('invalidRoleTransitions')]
    public function test_role_change_cannot_collapse_incarnation_or_invent_mutations(
        ?RoleRevision $before,
        ?RoleRevision $after
    ): void {
        $this->expectException(DomainException::class);
        new AuditRoleRevisions($before, $after);
    }

    /** @phpstan-return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidRoleRepresentations(): iterable
    {
        $initial = RoleRevision::initial()->toString();
        yield 'missing predecessor' => [['after' => $initial]];
        yield 'missing successor' => [['before' => $initial]];
        yield 'scalar revision loses incarnation' => [['before' => 1, 'after' => null]];
        yield 'invalid successor' => [['before' => null, 'after' => false]];
        yield 'malformed complete revision' => [['before' => null, 'after' => 'revision-label']];
        yield 'no snapshot capture' => [['before' => null, 'after' => $initial, 'snapshot' => []]];
    }

    /** @phpstan-param array<array-key, mixed> $data */
    #[DataProvider('invalidRoleRepresentations')]
    public function test_role_revision_hydration_requires_explicit_complete_state(array $data): void
    {
        $this->expectException(DomainException::class);
        AuditRoleRevisions::fromArray($data);
    }
}
