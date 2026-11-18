<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRolePermissionOutcome;
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
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\MessageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
abstract class AuditStorageConformance extends TestCase
{
    public function test_all_supported_fact_shapes_and_actor_kinds_survive_storage_reopening(): void
    {
        $userId = UserId::generate();
        $actors = [$userId, AgentId::fromString($userId->toString()), SystemId::generate(), AnonymousId::generate()];
        $records = [];
        foreach ($actors as $actor) {
            foreach ($this->facts() as [$subject, $action, $facts]) {
                $records[] = AuditEvidence::record(
                    $actor,
                    $subject,
                    $action,
                    new DateTimeImmutable('2030-01-02T03:04:05.123456-04:00'),
                    $facts,
                    new AuditCorrelation(MessageId::generate(), new AgentOperationKey(
                        new AgentOperationScope('audit-model', 'user', $userId->toString()),
                        AgentOperationId::generate()
                    ))
                );
            }

            [$subject, $action, $facts] = $this->facts()[0];
            $operationKey = new AgentOperationKey(
                new AgentOperationScope('audit-model', 'user', $userId->toString()),
                AgentOperationId::generate()
            );
            $correlations = [
                null,
                new AuditCorrelation(MessageId::generate(), null),
                new AuditCorrelation(null, $operationKey)
            ];
            foreach ($correlations as $correlation) {
                $records[] = AuditEvidence::record(
                    $actor,
                    $subject,
                    $action,
                    new DateTimeImmutable('2030-01-02T03:04:05.123456-04:00'),
                    $facts,
                    $correlation
                );
            }
        }

        $restored = $this->persistAndReopen($records);
        self::assertCount(count($records), $restored);
        $identities = [];
        foreach ($records as $index => $original) {
            $record = $restored[$index];
            self::assertNotSame($original, $record);
            // Complete typed equality includes every revision/incarnation, relationship and scoped correlation field.
            self::assertSame($original->toArray(), $record->toArray());
            self::assertSame($original->actorId()::class, $record->actorId()::class);
            self::assertSame($original->getSubject()->getId()::class, $record->getSubject()->getId()::class);
            self::assertNotSame(SystemId::generate()->toString(), $record->getId()->toString());
            $identities[] = $record->getId()->toString();
        }

        self::assertSame($identities, array_values(array_unique($identities)));
        self::assertSame($actors[2]->toString(), $actors[3]->toString());
        self::assertNotSame($actors[2]::class, $actors[3]::class);
    }

    public function test_unsupported_evidence_fails_instead_of_disappearing_or_falling_back(): void
    {
        [$subject, $action, $facts] = $this->facts()[0];
        $valid = AuditEvidence::record(
            SystemId::generate(),
            $subject,
            $action,
            new DateTimeImmutable(),
            $facts,
            null
        )->toArray();
        foreach (
            [
            ['action' => 'unsupported.action'],
            ['actor' => ['kind' => 'unsupported', 'id' => SystemId::generate()->toString()]],
            ['facts' => ['unadmitted_field' => 'not-admitted']],
            ['subject' => ['kind' => 'unsupported', 'id' => UserId::generate()->toString()]],
            ['correlation' => ['message' => []]],
            ['unadmitted_field' => 'not-admitted']
            ] as $change
        ) {
            try {
                $this->hydrateUnsupported(array_replace($valid, $change));
                self::fail('Unsupported evidence was accepted or discarded.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param list<AuditEvidence> $records
     * @return list<AuditEvidence>
     */
    abstract protected function persistAndReopen(array $records): array;

    /** @param array<array-key, mixed> $payload */
    abstract protected function hydrateUnsupported(array $payload): void;

    /** @return list<array{AuditSubject, AuditAction, AuditUserOutcome|AuditGrantOutcome|AuditAgentOutcome|AuditSessionRevocation|AuditRoleRevisions|AuditFeatureOutcome|AuditPermissionOutcome|AuditRolePermissionOutcome}> */
    private function facts(): array
    {
        $before = new AuditUserRevisions(UserState::ACTIVE, 3, 7, 2, 5, 1);
        $after = new AuditUserRevisions(UserState::ACTIVE, 4, 8, 2, 5, 1);
        $role = RoleRevision::initial();
        $permission = PermissionId::generate();
        $grant = ActivationGrantId::generate();
        $delivery = ActivationDeliveryId::generate();
        $user = new AuditSubject(UserId::generate());
        $roleSubject = new AuditSubject(RoleId::generate());

        $facts = [
            [$user, AuditAction::PASSWORD_CHANGED, new AuditUserOutcome($before, $after)],
            [$user, AuditAction::INVITATION_DELIVERY_CONFIRMED, new AuditGrantOutcome(
                new AuditGrantState($grant, 4, $delivery),
                new AuditGrantState($grant, 5, $delivery),
                CredentialDeliveryStatus::DELIVERED
            )],
            [$user, AuditAction::SESSION_ADMINISTRATIVELY_REVOKED, new AuditSessionRevocation(
                RefreshSessionId::generate(),
                4,
                5
            )],
            [new AuditSubject(AgentId::generate()), AuditAction::AGENT_PROVISIONED, new AuditAgentOutcome(
                null,
                new AuditAgentState(AgentCredentialId::generate(), 0, AgentState::ACTIVE),
                AgentDeliveryId::generate()
            )],
            [$roleSubject, AuditAction::ROLE_CREATED, new AuditRoleRevisions(null, $role)],
            [$roleSubject, AuditAction::ROLE_RENAMED, new AuditRoleRevisions($role, $role->next())],
            [$roleSubject, AuditAction::ROLE_REMOVED, new AuditRoleRevisions($role, null)],
            [$roleSubject, AuditAction::ROLE_PERMISSION_GRANTED, new AuditRolePermissionOutcome(
                new AuditRoleRevisions($role, $role->next()),
                $permission
            )],
            [new AuditSubject(FeatureId::generate()), AuditAction::FEATURE_CREATED, new AuditFeatureOutcome(
                null,
                new AuditFeatureState(1, FeatureStatus::OFF, $permission)
            )],
            [new AuditSubject($permission), AuditAction::PERMISSION_CREATED, new AuditPermissionOutcome(
                null,
                new AuditPermissionState(PermissionName::fromString('AUDIT_MODEL'), PermissionTier::ADMIN_SAFE, false)
            )]
        ];

        $activationBefore = new AuditGrantState($grant, 4, $delivery);
        $activationRetained = new AuditGrantState($grant, 5, $delivery);
        $activationAfter = new AuditGrantState(ActivationGrantId::generate(), 0, ActivationDeliveryId::generate());
        $reset = PasswordResetGrantId::generate();
        $resetDelivery = PasswordResetDeliveryId::generate();
        $resetBefore = new AuditGrantState($reset, 4, $resetDelivery);
        $resetAfter = new AuditGrantState($reset, 5, $resetDelivery);
        $email = EmailChangeGrantId::generate();
        $emailDelivery = EmailChangeDeliveryId::generate();
        $emailBefore = new AuditGrantState($email, 4, $emailDelivery);
        $emailAfter = new AuditGrantState($email, 5, $emailDelivery);
        $pendingBefore = new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 0, 0, 0, 0);
        $pendingAfter = new AuditUserRevisions(UserState::PENDING_ACTIVATION, 1, 0, 0, 0, 1);
        $facts[] = [$user, AuditAction::USER_INVITED, new AuditUserOutcome(
            null,
            $pendingBefore,
            null,
            $activationAfter
        )];
        $facts[] = [$user, AuditAction::USER_PENDING_INVITATION_CORRECTED, new AuditUserOutcome(
            $pendingBefore,
            $pendingAfter,
            $activationBefore,
            $activationAfter,
            $activationRetained
        )];
        $facts[] = [$user, AuditAction::PASSWORD_RESET_COMPLETED, new AuditUserOutcome(
            $before,
            $after,
            $resetBefore,
            $resetAfter
        )];
        $facts[] = [$user, AuditAction::EMAIL_CHANGE_REQUESTED, new AuditUserOutcome(
            $before,
            new AuditUserRevisions(UserState::ACTIVE, 3, 7, 2, 6, 1),
            $emailBefore,
            new AuditGrantState(EmailChangeGrantId::generate(), 0, EmailChangeDeliveryId::generate()),
            $emailBefore
        )];
        $facts[] = [$user, AuditAction::INVITATION_RESENT, new AuditGrantOutcome(
            $activationBefore,
            $activationAfter,
            null,
            null,
            $activationRetained
        )];
        $newReset = new AuditGrantState(PasswordResetGrantId::generate(), 0, PasswordResetDeliveryId::generate());
        $facts[] = [$user, AuditAction::PASSWORD_RESET_REQUESTED, new AuditGrantOutcome(null, $newReset)];
        // Reset replacement can retain an already-terminal predecessor without another revision advance.
        $facts[] = [$user, AuditAction::PASSWORD_RESET_REQUESTED, new AuditGrantOutcome(
            $resetBefore,
            $newReset,
            null,
            null,
            $resetBefore
        )];
        foreach (
            [
                [$activationBefore, $activationRetained, AuditAction::INVITATION_DELIVERY_FAILED,
                    AuditAction::INVITATION_DELIVERY_CONFIRMED],
                [$resetBefore, $resetAfter, AuditAction::PASSWORD_RESET_DELIVERY_FAILED,
                    AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED],
                [$emailBefore, $emailAfter, AuditAction::EMAIL_CHANGE_DELIVERY_FAILED,
                    AuditAction::EMAIL_CHANGE_DELIVERY_CONFIRMED]
            ] as [$grantBefore, $grantAfter, $failedAction, $confirmedAction]
        ) {
            foreach (CredentialDeliveryFailure::cases() as $failure) {
                $status = CredentialDeliveryStatus::RETRY_PENDING;
                if ($failure === CredentialDeliveryFailure::PERMANENT_PROVIDER) {
                    $status = CredentialDeliveryStatus::PERMANENT_FAILURE;
                }

                $facts[] = [$user, $failedAction, new AuditGrantOutcome($grantBefore, $grantAfter, $status, $failure)];
            }

            $unclassifiedStatuses = [
                CredentialDeliveryStatus::RETRY_PENDING,
                CredentialDeliveryStatus::PERMANENT_FAILURE
            ];
            foreach ($unclassifiedStatuses as $status) {
                $facts[] = [$user, $failedAction, new AuditGrantOutcome($grantBefore, $grantAfter, $status)];
            }

            $facts[] = [$user, $confirmedAction, new AuditGrantOutcome(
                $grantBefore,
                $grantAfter,
                CredentialDeliveryStatus::DELIVERED
            )];
        }

        $credential = AgentCredentialId::generate();
        $facts[] = [new AuditSubject(AgentId::generate()), AuditAction::AGENT_CREDENTIAL_REVOKED, new AuditAgentOutcome(
            new AuditAgentState($credential, 3, AgentState::ACTIVE),
            new AuditAgentState($credential, 3, AgentState::REVOKED),
            null
        )];
        $facts[] = [new AuditSubject(FeatureId::generate()), AuditAction::FEATURE_PERMISSION_CHANGED,
            new AuditFeatureOutcome(
                new AuditFeatureState(3, FeatureStatus::PREVIEW, $permission),
                new AuditFeatureState(4, FeatureStatus::PREVIEW, PermissionId::generate())
            )];
        $facts[] = [new AuditSubject(FeatureId::generate()), AuditAction::FEATURE_REMOVED, new AuditFeatureOutcome(
            new AuditFeatureState(3, FeatureStatus::ON, $permission),
            null
        )];
        $facts[] = [new AuditSubject($permission), AuditAction::PERMISSION_RECONCILED, new AuditPermissionOutcome(
            new AuditPermissionState(PermissionName::fromString('AUDIT_MODEL'), PermissionTier::ADMIN_SAFE, true),
            new AuditPermissionState(PermissionName::fromString('AUDIT_MODEL'), PermissionTier::SUPER_ADMIN_ONLY, true)
        )];
        $facts[] = [new AuditSubject($permission), AuditAction::PERMISSION_REMOVED, new AuditPermissionOutcome(
            new AuditPermissionState(PermissionName::fromString('AUDIT_MODEL'), PermissionTier::ADMIN_SAFE, false),
            null
        )];

        return $facts;
    }
}
