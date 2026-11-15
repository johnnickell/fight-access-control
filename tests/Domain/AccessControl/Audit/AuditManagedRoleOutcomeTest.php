<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditManagedRoleOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditManagedRoleOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AuditManagedRoleOutcomeTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidRelationships(): iterable
    {
        yield 'missing before' => [null, []];
        yield 'missing after' => [[], null];
        yield 'not list' => [['permission' => PermissionId::generate()], []];
        yield 'not typed' => [['not an ID'], []];
        $id = PermissionId::generate();
        yield 'duplicate' => [[$id, $id], []];
    }

    #[DataProvider('invalidRelationships')]
    public function test_relationships_match_revision_states_and_are_unique_typed_lists(
        mixed $before,
        mixed $after
    ): void {
        $revision = RoleRevision::initial();
        $this->expectException(DomainException::class);
        new AuditManagedRoleOutcome(new AuditRoleRevisions($revision, $revision->next()), $before, $after);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $revision = RoleRevision::initial();
        $valid = [
            'revisions'             => ['before' => $revision->toString(), 'after' => $revision->next()->toString()],
            'before_permission_ids' => [],
            'after_permission_ids'  => []
        ];
        yield 'missing all' => [[]];
        yield 'bad revisions' => [[...$valid, 'revisions' => false]];
        yield 'bad list' => [[...$valid, 'before_permission_ids' => false]];
        yield 'map' => [[...$valid, 'after_permission_ids' => ['key' => PermissionId::generate()->toString()]]];
        yield 'coercive member' => [[...$valid, 'after_permission_ids' => [42]]];
        yield 'duplicate member' => [[
            ...$valid, 'before_permission_ids' => array_fill(0, 2, PermissionId::generate()->toString())
        ]];
        yield 'extra field' => [[...$valid, 'diagnostics' => 'not admitted']];
    }

    #[DataProvider('invalidPayloads')]
    public function test_persisted_facts_reject_incomplete_or_coercive_values(array $data): void
    {
        $this->expectException(DomainException::class);
        AuditManagedRoleOutcome::fromArray($data);
    }

    public function test_actions_require_matching_managed_role_transition_and_subject(): void
    {
        $revision = RoleRevision::initial();
        $outcomes = [
            new AuditManagedRoleOutcome(new AuditRoleRevisions(null, $revision), null, []),
            new AuditManagedRoleOutcome(new AuditRoleRevisions($revision, $revision->next()), [], []),
            new AuditManagedRoleOutcome(new AuditRoleRevisions($revision, null), [], null)
        ];
        $actions = [
            AuditAction::MANAGED_ROLE_CREATED, AuditAction::MANAGED_ROLE_RECONCILED, AuditAction::MANAGED_ROLE_REMOVED
        ];
        foreach ($outcomes as $index => $facts) {
            foreach ([...$actions, AuditAction::ROLE_RENAMED] as $action) {
                if ($action === $actions[$index]) {
                    $evidence = AuditEvidence::record(
                        SystemId::generate(),
                        new AuditSubject(RoleId::generate()),
                        $action,
                        new DateTimeImmutable(),
                        $facts,
                        null
                    );
                    self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
                    continue;
                }

                try {
                    AuditEvidence::record(
                        SystemId::generate(),
                        new AuditSubject(RoleId::generate()),
                        $action,
                        new DateTimeImmutable(),
                        $facts,
                        null
                    );
                    self::fail('The action must match the actual managed Role transition.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }

        $this->expectException(DomainException::class);
        AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject(PermissionId::generate()),
            AuditAction::MANAGED_ROLE_CREATED,
            new DateTimeImmutable(),
            $outcomes[0],
            null
        );
    }
}
