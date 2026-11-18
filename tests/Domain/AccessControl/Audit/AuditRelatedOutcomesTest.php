<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRolePermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditAction::class)]
#[CoversClass(AuditFeatureState::class)]
#[CoversClass(AuditFeatureOutcome::class)]
#[CoversClass(AuditPermissionState::class)]
#[CoversClass(AuditPermissionOutcome::class)]
#[CoversClass(AuditRolePermissionOutcome::class)]
#[CoversClass(AuditGrantState::class)]
#[CoversClass(AuditGrantOutcome::class)]
#[CoversClass(AuditUserOutcome::class)]
final class AuditRelatedOutcomesTest extends TestCase
{
    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalidFacts(): iterable
    {
        $permission = PermissionId::generate();
        $feature = new AuditFeatureState(1, FeatureStatus::OFF, $permission);
        $definition = new AuditPermissionState(
            PermissionName::fromString('AUDIT_READ'),
            PermissionTier::ADMIN_SAFE,
            false
        );
        $id = ActivationGrantId::generate();
        $delivery = ActivationDeliveryId::generate();
        $before = new AuditGrantState($id, 4, $delivery);
        $after = new AuditGrantState(ActivationGrantId::generate(), 0, ActivationDeliveryId::generate());
        $user = new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 1, 1, 1, 1);
        yield 'Feature revision zero' => [static fn (): AuditFeatureState => new AuditFeatureState(
            0,
            FeatureStatus::OFF,
            $permission
        )];
        yield 'Feature empty state' => [static fn (): AuditFeatureState => AuditFeatureState::fromArray([])];
        yield 'Feature coerced revision' => [static fn (): AuditFeatureState => AuditFeatureState::fromArray([
            'revision'      => '1',
            'status'        => 'off',
            'permission_id' => $permission->toString()
        ])];
        yield 'Feature absent states' => [static fn (): AuditFeatureOutcome => new AuditFeatureOutcome(null, null)];
        yield 'Feature unrelated action' => [static fn (): AuditEvidence => AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject(FeatureId::generate()),
            AuditAction::PASSWORD_CHANGED,
            new DateTimeImmutable(),
            new AuditFeatureOutcome(null, $feature),
            null
        )];
        yield 'Permission unrelated action' => [static fn (): AuditEvidence => AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject($permission),
            AuditAction::PASSWORD_CHANGED,
            new DateTimeImmutable(),
            new AuditPermissionOutcome(null, $definition),
            null
        )];
        yield 'User nullable field coercion' => [static fn (): AuditUserOutcome => AuditUserOutcome::fromArray([
            'before'               => false,
            'after'                => $user->toArray(),
            'grant_before'         => null,
            'grant_after'          => null,
            'replaced_predecessor' => null
        ])];
        yield 'Feature unchanged revision' => [static fn (): AuditFeatureOutcome => new AuditFeatureOutcome(
            $feature,
            $feature
        )];
        yield 'Feature incomplete outcome' => [static fn (): AuditFeatureOutcome => AuditFeatureOutcome::fromArray([])];
        yield 'Feature coercive outcome' => [static fn (): AuditFeatureOutcome => AuditFeatureOutcome::fromArray([
            'before' => 1,
            'after'  => null
        ])];
        yield 'Permission protected custom' => [static fn (): AuditPermissionState => new AuditPermissionState(
            PermissionName::fromString('AUDIT_READ'),
            PermissionTier::SUPER_ADMIN_ONLY,
            false
        )];
        yield 'Permission incomplete state' => [static fn (): AuditPermissionState => AuditPermissionState::fromArray(
            []
        )];
        yield 'Permission coerced state' => [static fn (): AuditPermissionState => AuditPermissionState::fromArray([
            'name'    => 'AUDIT_READ',
            'tier'    => 'admin_safe',
            'managed' => 1
        ])];
        yield 'Permission absent states' => [static fn (): AuditPermissionOutcome => new AuditPermissionOutcome(
            null,
            null
        )];
        yield 'Permission unchanged state' => [static fn (): AuditPermissionOutcome => new AuditPermissionOutcome(
            $definition,
            $definition
        )];
        yield 'Permission incomplete' => [static fn (): AuditPermissionOutcome => AuditPermissionOutcome::fromArray(
            []
        )];
        yield 'Permission bad types' => [static fn (): AuditPermissionOutcome => AuditPermissionOutcome::fromArray([
            'before' => false,
            'after'  => null
        ])];
        yield 'Role Permission creation' => [static fn (): AuditRolePermissionOutcome => new AuditRolePermissionOutcome(
            new AuditRoleRevisions(null, RoleRevision::initial()),
            $permission
        )];
        yield 'Role Permission removal' => [static fn (): AuditRolePermissionOutcome => new AuditRolePermissionOutcome(
            new AuditRoleRevisions(RoleRevision::initial(), null),
            $permission
        )];
        yield 'Role incomplete' => [static fn (): AuditRolePermissionOutcome => AuditRolePermissionOutcome::fromArray(
            []
        )];
        yield 'Role types' => [static fn (): AuditRolePermissionOutcome => AuditRolePermissionOutcome::fromArray([
            'revisions'     => [],
            'permission_id' => 1
        ])];
        yield 'Permanent failure in retry state' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            new AuditGrantState($id, 5, $delivery),
            CredentialDeliveryStatus::RETRY_PENDING,
            CredentialDeliveryFailure::PERMANENT_PROVIDER
        )];
        yield 'Retry failure in permanent state' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            new AuditGrantState($id, 5, $delivery),
            CredentialDeliveryStatus::PERMANENT_FAILURE,
            CredentialDeliveryFailure::RETRYABLE_PROVIDER
        )];
        yield 'Grant retained identity mismatch' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            $after,
            replacedPredecessor: $after
        )];
        yield 'Grant retained delivery mismatch' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            $after,
            replacedPredecessor: new AuditGrantState($id, 5, ActivationDeliveryId::generate())
        )];
        yield 'Grant retained revision skipped' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            $after,
            replacedPredecessor: new AuditGrantState($id, 6, $delivery)
        )];
        yield 'Grant retained revision reversed' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            $after,
            replacedPredecessor: new AuditGrantState($id, 3, $delivery)
        )];
        yield 'Grant in-place invented retained state' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            $before,
            $before,
            replacedPredecessor: $before
        )];
        yield 'Grant initial invented retained state' => [static fn (): AuditGrantOutcome => new AuditGrantOutcome(
            null,
            $after,
            replacedPredecessor: $before
        )];
        yield 'User initial invented retained state' => [static fn (): AuditUserOutcome => new AuditUserOutcome(
            null,
            $user,
            null,
            $after,
            $before
        )];
        yield 'Grant retained shape coercion' => [static fn (): AuditGrantOutcome => AuditGrantOutcome::fromArray([
            'grant_before'         => null,
            'grant_after'          => $after->toArray(),
            'delivery_status'      => null,
            'failure'              => null,
            'replaced_predecessor' => false
        ])];
    }

    #[DataProvider('invalidFacts')]
    public function test_invalid_relationship_and_outcome_facts_are_rejected(Closure $construct): void
    {
        $this->expectException(DomainException::class);
        $construct();
    }

    public function test_replacements_preserve_both_original_and_retained_terminal_predecessor_revisions(): void
    {
        $id = ActivationGrantId::generate();
        $delivery = ActivationDeliveryId::generate();
        $before = new AuditGrantState($id, 4, $delivery);
        $terminal = new AuditGrantState($id, 5, $delivery);
        $after = new AuditGrantState(ActivationGrantId::generate(), 0, ActivationDeliveryId::generate());
        $facts = new AuditGrantOutcome($before, $after, replacedPredecessor: $terminal);
        self::assertSame($terminal, $facts->getReplacedPredecessor());
        self::assertSame($facts->toArray(), AuditGrantOutcome::fromArray($facts->toArray())->toArray());
        self::assertSame(4, $facts->toArray()['grant_before']['revision']);
        self::assertSame(5, $facts->toArray()['replaced_predecessor']['revision']);
        self::assertSame(0, $facts->toArray()['grant_after']['revision']);
        $revisions = new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 1, 1, 1, 1);
        $userFacts = new AuditUserOutcome($revisions, $revisions, $before, $after, $terminal);
        self::assertSame($terminal, $userFacts->getReplacedPredecessor());
        self::assertSame($userFacts->toArray(), AuditUserOutcome::fromArray($userFacts->toArray())->toArray());
        $unchangedTerminal = new AuditGrantOutcome($terminal, $after, replacedPredecessor: $terminal);
        self::assertSame(5, $unchangedTerminal->toArray()['replaced_predecessor']['revision']);
    }

    public function test_replacement_cannot_omit_its_actual_retained_predecessor(): void
    {
        $before = new AuditGrantState(ActivationGrantId::generate(), 4, ActivationDeliveryId::generate());
        $after = new AuditGrantState(ActivationGrantId::generate(), 0, ActivationDeliveryId::generate());
        $this->expectException(DomainException::class);
        new AuditGrantOutcome($before, $after);
    }

    public function test_provider_failure_classifications_round_trip_with_their_actual_outcomes(): void
    {
        $id = ActivationGrantId::generate();
        $deliveryId = ActivationDeliveryId::generate();
        foreach (CredentialDeliveryFailure::cases() as $failure) {
            $status = CredentialDeliveryStatus::RETRY_PENDING;
            if ($failure === CredentialDeliveryFailure::PERMANENT_PROVIDER) {
                $status = CredentialDeliveryStatus::PERMANENT_FAILURE;
            }

            $facts = new AuditGrantOutcome(
                new AuditGrantState($id, 1, $deliveryId),
                new AuditGrantState($id, 2, $deliveryId),
                $status,
                $failure
            );
            self::assertSame($facts->toArray(), AuditGrantOutcome::fromArray($facts->toArray())->toArray());
            self::assertSame($failure->value, $facts->toArray()['failure']);
            self::assertSame($status, $facts->getDeliveryStatus());
        }
    }

    public function test_feature_actions_preserve_actual_revisions_status_and_related_permission(): void
    {
        $feature = Feature::define(
            FeatureId::generate(),
            FeatureName::fromString('audit-example'),
            PermissionId::generate()
        );
        $off = AuditFeatureState::fromFeature($feature);
        $on = AuditFeatureState::fromFeature($feature->withStatus(FeatureStatus::ON));
        $rebound = AuditFeatureState::fromFeature($feature->withPermission(PermissionId::generate()));
        $cases = [
            [AuditAction::FEATURE_CREATED, new AuditFeatureOutcome(null, $off)],
            [AuditAction::FEATURE_STATUS_CHANGED, new AuditFeatureOutcome($off, $on)],
            [AuditAction::FEATURE_PERMISSION_CHANGED, new AuditFeatureOutcome($off, $rebound)],
            [AuditAction::FEATURE_REMOVED, new AuditFeatureOutcome($on, null)]
        ];
        foreach ($cases as [$action, $facts]) {
            $evidence = AuditEvidence::record(
                SystemId::generate(),
                new AuditSubject($feature->getId()),
                $action,
                new DateTimeImmutable(),
                $facts,
                null
            );
            self::assertSame($facts->toArray(), AuditFeatureOutcome::fromArray($facts->toArray())->toArray());
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
            self::assertSame($facts->getBefore()?->toArray(), $facts->toArray()['before']);
            self::assertSame($facts->getAfter()?->toArray(), $facts->toArray()['after']);
        }
    }

    public function test_feature_action_cannot_misrepresent_a_permission_change_as_a_status_change(): void
    {
        $before = new AuditFeatureState(1, FeatureStatus::OFF, PermissionId::generate());
        $after = new AuditFeatureState(2, FeatureStatus::OFF, PermissionId::generate());
        $this->expectException(DomainException::class);
        AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject(FeatureId::generate()),
            AuditAction::FEATURE_STATUS_CHANGED,
            new DateTimeImmutable(),
            new AuditFeatureOutcome($before, $after),
            null
        );
    }

    public function test_permission_actions_preserve_admitted_definition_values_without_invented_revisions(): void
    {
        $at = new DateTimeImmutable();
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('AUDIT_READ'),
            PermissionTier::ADMIN_SAFE,
            $at
        );
        $before = AuditPermissionState::fromPermission($permission);
        $after = AuditPermissionState::fromPermission($permission->reconcileManaged(
            PermissionName::fromString('AUDIT_WRITE'),
            PermissionTier::SUPER_ADMIN_ONLY,
            $at
        ));
        foreach (
            [
            [AuditAction::PERMISSION_CREATED, new AuditPermissionOutcome(null, $before)],
            [AuditAction::PERMISSION_RECONCILED, new AuditPermissionOutcome($before, $after)],
            [AuditAction::PERMISSION_REMOVED, new AuditPermissionOutcome($after, null)]
            ] as [$action, $facts]
        ) {
            $evidence = AuditEvidence::record(
                SystemId::generate(),
                new AuditSubject($permission->getId()),
                $action,
                $at,
                $facts,
                null
            );
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
            self::assertSame($facts->getBefore()?->toArray(), $facts->toArray()['before']);
            self::assertSame($facts->getAfter()?->toArray(), $facts->toArray()['after']);
            self::assertArrayNotHasKey('revision', $before->toArray());
        }
    }

    /** @return iterable<string, array{bool, string, bool, bool}> */
    public static function contradictoryCreationAndOwnership(): iterable
    {
        foreach ([false, true] as $hydrate) {
            foreach (['on', 'preview'] as $status) {
                yield 'Feature creation '.$status.' hydrate '.(int) $hydrate => [$hydrate, $status, true, true];
            }

            foreach ([[false, false], [false, true], [true, false]] as [$beforeManaged, $afterManaged]) {
                yield 'Permission reconcile '.(int) $beforeManaged.' to '.(int) $afterManaged.' hydrate '.(int) $hydrate
                    => [$hydrate, '', $beforeManaged, $afterManaged];
            }
        }
    }

    #[DataProvider('contradictoryCreationAndOwnership')]
    public function test_creation_and_reconciliation_cannot_contradict_domain_initial_state_or_ownership(
        bool $hydrate,
        string $featureStatus,
        bool $beforeManaged,
        bool $afterManaged
    ): void {
        $at = new DateTimeImmutable();
        $subject = new AuditSubject(FeatureId::generate());
        $action = AuditAction::FEATURE_CREATED;
        $facts = new AuditFeatureOutcome(null, new AuditFeatureState(
            1,
            FeatureStatus::from($featureStatus === '' ? 'off' : $featureStatus),
            PermissionId::generate()
        ));
        if ($featureStatus === '') {
            $subject = new AuditSubject(PermissionId::generate());
            $action = AuditAction::PERMISSION_RECONCILED;
            $facts = new AuditPermissionOutcome(
                new AuditPermissionState(
                    PermissionName::fromString('AUDIT_READ'),
                    PermissionTier::ADMIN_SAFE,
                    $beforeManaged
                ),
                new AuditPermissionState(
                    PermissionName::fromString('AUDIT_WRITE'),
                    PermissionTier::ADMIN_SAFE,
                    $afterManaged
                )
            );
        }

        $this->expectException(DomainException::class);
        if ($hydrate) {
            AuditEvidence::fromArray([
                'id'          => AuditEvidenceId::generate()->toString(),
                'actor'       => ['kind' => 'system', 'id' => SystemId::generate()->toString()],
                'subject'     => $subject->toArray(),
                'action'      => $action->value,
                'occurred_at' => $at->format('Y-m-d\TH:i:s.uP'),
                'correlation' => null,
                'facts'       => $facts->toArray()
            ]);

            return;
        }

        AuditEvidence::record(SystemId::generate(), $subject, $action, $at, $facts, null);
    }

    public function test_role_permission_relationship_preserves_incarnation_and_the_related_permission(): void
    {
        $at = new DateTimeImmutable();
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_AUDIT'), [], $at);
        $permission = PermissionId::generate();
        $granted = $role->grantPermissionToCustom($permission, $at);
        $revoked = $granted->revokePermissionFromCustom($permission, $at);
        foreach (
            [
                [AuditAction::ROLE_PERMISSION_GRANTED, $role, $granted],
                [AuditAction::ROLE_PERMISSION_REVOKED, $granted, $revoked]
            ] as [$action, $before, $after]
        ) {
            $revisions = new AuditRoleRevisions($before->getRevision(), $after->getRevision());
            $facts = new AuditRolePermissionOutcome($revisions, $permission);
            self::assertSame($permission, $facts->getPermissionId());
            self::assertSame($revisions, $facts->getRevisions());
            $evidence = AuditEvidence::record(
                UserId::generate(),
                new AuditSubject($role->getId()),
                $action,
                $at,
                $facts,
                null
            );
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        }
    }
}
