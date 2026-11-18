<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRoleOutcome;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditUserRoleOutcome::class)]
#[CoversClass(AuditUserRevisions::class)]
#[CoversClass(AuditEvidence::class)]
final class AuditUserRoleOutcomeTest extends TestCase
{
    public function test_complete_typed_relationship_and_real_assignment_counters_round_trip(): void
    {
        $before = new AuditUserRevisions(UserState::DISABLED, 3, 8, 4, 2, 1);
        $after = new AuditUserRevisions(UserState::DISABLED, 3, 8, 5, 2, 1);
        $role = RoleId::generate();
        $revision = RoleRevision::initial()->next();
        $facts = new AuditUserRoleOutcome($before, $after, $role, $revision);
        self::assertSame($before, $facts->getBefore());
        self::assertSame($after, $facts->getAfter());
        self::assertSame($role, $facts->getRoleId());
        self::assertSame($revision, $facts->getRoleReferenceRevision());
        self::assertSame($facts->toArray(), AuditUserRoleOutcome::fromArray($facts->toArray())->toArray());
        foreach ([AuditAction::USER_ROLE_ASSIGNED, AuditAction::USER_ROLE_REMOVED] as $action) {
            $record = AuditEvidence::record(
                UserId::generate(),
                new AuditSubject(UserId::generate()),
                $action,
                new DateTimeImmutable('2030-01-02T00:00:00Z'),
                $facts,
                null
            );
            self::assertSame($record->toArray(), AuditEvidence::fromArray($record->toArray())->toArray());
        }
    }

    public function test_rejects_incomplete_coercive_or_unadmitted_relationship_fields(): void
    {
        $facts = $this->facts();
        $data = $facts->toArray();
        $invalid = [];
        foreach (array_keys($data) as $key) {
            $missing = $data;
            unset($missing[$key]);
            $invalid[] = $missing;
            $wrongType = $data;
            $wrongType[$key] = 1;
            $invalid[] = $wrongType;
        }

        $invalid[] = [...$data, 'diagnostics' => 'Unadmitted text.'];
        $invalid[] = [...$data, 'role_reference_revision' => '1'];
        $invalid[] = [...$data, 'role_id' => 'not-an-id'];
        foreach ($invalid as $payload) {
            try {
                AuditUserRoleOutcome::fromArray($payload);
                self::fail('Unadmitted facts were accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_fabricated_revisions_lifecycle_and_authentication_changes(): void
    {
        $before = new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 0, 7, 0, 0);
        $invalid = [
            new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 0, 7, 0, 0),
            new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 0, 9, 0, 0),
            new AuditUserRevisions(UserState::PENDING_ACTIVATION, 2, 0, 8, 0, 0),
            new AuditUserRevisions(UserState::ACTIVE, 1, 0, 8, 0, 0)
        ];
        foreach ($invalid as $after) {
            try {
                new AuditUserRoleOutcome($before, $after, RoleId::generate(), RoleRevision::initial());
                self::fail('Contradictory membership state was accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_wrong_primary_subject_or_action_cannot_admit_membership_facts(): void
    {
        $invalid = [
            [new AuditSubject(RoleId::generate()), AuditAction::USER_ROLE_ASSIGNED],
            [new AuditSubject(UserId::generate()), AuditAction::ROLE_PERMISSION_GRANTED],
            [new AuditSubject(UserId::generate()), AuditAction::USER_ENABLED]
        ];
        foreach ($invalid as [$subject, $action]) {
            try {
                AuditEvidence::record(
                    UserId::generate(),
                    $subject,
                    $action,
                    new DateTimeImmutable('2030-01-02T00:00:00Z'),
                    $this->facts(),
                    null
                );
                self::fail('Contradictory subject/action was accepted.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function facts(): AuditUserRoleOutcome
    {
        return new AuditUserRoleOutcome(
            new AuditUserRevisions(UserState::ACTIVE, 1, 0, 0, 0, 0),
            new AuditUserRevisions(UserState::ACTIVE, 1, 0, 1, 0, 0),
            RoleId::generate(),
            RoleRevision::initial()
        );
    }
}
