<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditEvidence
 *
 * Represents an admitted outcome, not a diagnostic payload, authorization decision or public response.
 *
 * @phpstan-import-type SubjectPayload from AuditSubject
 * @phpstan-import-type CorrelationPayload from AuditCorrelation
 * @phpstan-import-type UserOutcomePayload from AuditUserOutcome
 * @phpstan-import-type GrantOutcomePayload from AuditGrantOutcome
 * @phpstan-import-type AgentOutcomePayload from AuditAgentOutcome
 * @phpstan-import-type SessionRevocationPayload from AuditSessionRevocation
 * @phpstan-import-type RevisionsPayload from AuditRoleRevisions
 * @phpstan-import-type FeatureOutcomePayload from AuditFeatureOutcome
 * @phpstan-import-type PermissionOutcomePayload from AuditPermissionOutcome
 * @phpstan-import-type RolePermissionPayload from AuditRolePermissionOutcome
 * @phpstan-type EvidencePayload array{
 *     id: string, actor: array{kind: 'user'|'agent'|'system'|'anonymous', id: string},
 *     subject: SubjectPayload, action: string, occurred_at: string, correlation: ?CorrelationPayload,
 *     facts: UserOutcomePayload|GrantOutcomePayload|AgentOutcomePayload|SessionRevocationPayload|RevisionsPayload
 *         |FeatureOutcomePayload|PermissionOutcomePayload|RolePermissionPayload
 * }
 *
 * @phpstan-consistent-constructor
 */
class AuditEvidence implements Arrayable
{
    /**
     * Constructs AuditEvidence
     *
     * @throws DomainException When subject, action and admitted facts do not describe the same outcome
     */
    protected function __construct(
        private readonly AuditEvidenceId $id,
        private readonly UserId|AgentId|SystemId|AnonymousId $actorId,
        private readonly AuditSubject $subject,
        private readonly AuditAction $action,
        private readonly DateTimeImmutable $occurredAt,
        private readonly AuditUserOutcome|AuditGrantOutcome|AuditAgentOutcome
        |AuditSessionRevocation|AuditRoleRevisions|AuditFeatureOutcome|AuditPermissionOutcome
        |AuditRolePermissionOutcome $facts,
        private readonly ?AuditCorrelation $correlation
    ) {
        $subjectId = $subject->getId();
        if ($facts instanceof AuditUserOutcome && $subjectId instanceof UserId) {
            $this->validateUserOutcome($facts);

            return;
        }

        if ($facts instanceof AuditGrantOutcome && $subjectId instanceof UserId) {
            $this->validateGrantOutcome($facts);

            return;
        }

        if ($facts instanceof AuditAgentOutcome && $subjectId instanceof AgentId) {
            $this->validateAgentOutcome($facts);

            return;
        }

        if (
            $facts instanceof AuditSessionRevocation && $subjectId instanceof UserId
            && $action === AuditAction::SESSION_ADMINISTRATIVELY_REVOKED
        ) {
            return;
        }

        if ($facts instanceof AuditRoleRevisions && $subjectId instanceof RoleId) {
            $valid = match ($action) {
                AuditAction::ROLE_CREATED => $facts->getBefore() === null && $facts->getAfter() !== null,
                AuditAction::ROLE_RENAMED => $facts->getBefore() !== null && $facts->getAfter() !== null,
                AuditAction::ROLE_REMOVED => $facts->getBefore() !== null && $facts->getAfter() === null,
                default => false
            };
            if ($valid) {
                return;
            }
        }

        if (
            $facts instanceof AuditRolePermissionOutcome && $subjectId instanceof RoleId
            && in_array($action, [AuditAction::ROLE_PERMISSION_GRANTED, AuditAction::ROLE_PERMISSION_REVOKED], true)
        ) {
            return;
        }

        if ($facts instanceof AuditPermissionOutcome && $subjectId instanceof PermissionId) {
            $valid = match ($action) {
                AuditAction::PERMISSION_CREATED => $facts->getBefore() === null,
                AuditAction::PERMISSION_RECONCILED => $facts->getBefore() !== null && $facts->getAfter() !== null,
                AuditAction::PERMISSION_REMOVED => $facts->getAfter() === null,
                default => false
            };
            if ($valid) {
                return;
            }
        }

        if ($facts instanceof AuditFeatureOutcome && $subjectId instanceof FeatureId) {
            $before = $facts->getBefore()?->toArray();
            $after = $facts->getAfter()?->toArray();
            $valid = match ($action) {
                AuditAction::FEATURE_CREATED => $before === null && $after !== null && $after['revision'] === 1,
                AuditAction::FEATURE_REMOVED => $after === null,
                AuditAction::FEATURE_STATUS_CHANGED => $before !== null && $after !== null
                    && $before['status'] !== $after['status'] && $before['permission_id'] === $after['permission_id'],
                AuditAction::FEATURE_PERMISSION_CHANGED => $before !== null && $after !== null
                    && $before['status'] === $after['status'] && $before['permission_id'] !== $after['permission_id'],
                default => false
            };
            if ($valid) {
                return;
            }
        }

        throw new DomainException('Audit subject, action and facts must describe the same supported outcome.');
    }

    /**
     * Records a new outcome with a unique evidence identity and explicitly supplied trusted time
     */
    public static function record(
        UserId|AgentId|SystemId|AnonymousId $actorId,
        AuditSubject $subject,
        AuditAction $action,
        DateTimeImmutable $occurredAt,
        AuditUserOutcome|AuditGrantOutcome|AuditAgentOutcome|AuditSessionRevocation|AuditRoleRevisions
        |AuditFeatureOutcome|AuditPermissionOutcome|AuditRolePermissionOutcome $facts,
        ?AuditCorrelation $correlation
    ): static {
        return new static(AuditEvidenceId::generate(), $actorId, $subject, $action, $occurredAt, $facts, $correlation);
    }

    /**
     * Creates only the complete current contract without inferring identity, kind, time or revisions
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When persisted fields are missing, unsupported or coercive
     */
    public static function fromArray(array $data): static
    {
        if (
            count($data) !== 7 || !isset(
                $data['id'],
                $data['actor'],
                $data['subject'],
                $data['action'],
                $data['occurred_at'],
                $data['facts']
            )
            || !is_string($data['id']) || !is_array($data['actor']) || !is_array($data['subject'])
            || !is_string($data['action']) || !is_string($data['occurred_at']) || !is_array($data['facts'])
            || !array_key_exists('correlation', $data)
            || ($data['correlation'] !== null && !is_array($data['correlation']))
        ) {
            throw new DomainException('Audit evidence requires the complete current representation.');
        }

        $actor = $data['actor'];
        if (
            count($actor) !== 2 || !isset($actor['kind'], $actor['id'])
            || !is_string($actor['kind']) || !is_string($actor['id'])
        ) {
            throw new DomainException('Audit actor requires an explicit kind and identity.');
        }

        $actorKind = AuditActorKind::tryFrom($actor['kind']);
        if ($actorKind === null) {
            throw new DomainException('Audit actor kind is unsupported.');
        }

        $actorId = $actorKind->idFromString($actor['id']);
        $action = AuditAction::tryFrom($data['action']) ?? throw new DomainException('Audit action is unsupported.');
        $occurredAt = CredentialDeliveryTimestamp::fromString($data['occurred_at'])->toDateTimeImmutable();
        if ($occurredAt->format('Y-m-d\TH:i:s.uP') !== $data['occurred_at']) {
            throw new DomainException('Audit occurrence time requires its complete canonical precision and offset.');
        }

        $facts = match ($action) {
            AuditAction::AGENT_PROVISIONED, AuditAction::AGENT_CREDENTIAL_ROTATED,
                AuditAction::AGENT_CREDENTIAL_REVOKED => AuditAgentOutcome::fromArray($data['facts']),
            AuditAction::INVITATION_RESENT, AuditAction::INVITATION_RETRY_REQUESTED,
            AuditAction::INVITATION_DELIVERY_FAILED, AuditAction::INVITATION_DELIVERY_CONFIRMED,
            AuditAction::PASSWORD_RESET_REQUESTED, AuditAction::PASSWORD_RESET_DELIVERY_FAILED,
            AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED, AuditAction::EMAIL_CHANGE_DELIVERY_FAILED,
                AuditAction::EMAIL_CHANGE_DELIVERY_CONFIRMED => AuditGrantOutcome::fromArray($data['facts']),
            AuditAction::SESSION_ADMINISTRATIVELY_REVOKED => AuditSessionRevocation::fromArray($data['facts']),
            AuditAction::ROLE_CREATED, AuditAction::ROLE_RENAMED,
                AuditAction::ROLE_REMOVED => AuditRoleRevisions::fromArray($data['facts']),
            AuditAction::ROLE_PERMISSION_GRANTED, AuditAction::ROLE_PERMISSION_REVOKED
                => AuditRolePermissionOutcome::fromArray($data['facts']),
            AuditAction::PERMISSION_CREATED, AuditAction::PERMISSION_RECONCILED, AuditAction::PERMISSION_REMOVED
                => AuditPermissionOutcome::fromArray($data['facts']),
            AuditAction::FEATURE_CREATED, AuditAction::FEATURE_STATUS_CHANGED,
            AuditAction::FEATURE_PERMISSION_CHANGED, AuditAction::FEATURE_REMOVED
                => AuditFeatureOutcome::fromArray($data['facts']),
            default => AuditUserOutcome::fromArray($data['facts'])
        };

        return new static(
            AuditEvidenceId::fromString($data['id']),
            $actorId,
            AuditSubject::fromArray($data['subject']),
            $action,
            $occurredAt,
            $facts,
            $data['correlation'] === null ? null : AuditCorrelation::fromArray($data['correlation'])
        );
    }

    /**
     * Returns the unique record identity
     */
    public function getId(): AuditEvidenceId
    {
        return $this->id;
    }

    /**
     * Returns direct typed originating provenance without granting business authority
     */
    public function actorId(): UserId|AgentId|SystemId|AnonymousId
    {
        return $this->actorId;
    }

    /**
     * Returns the package-defined outcome
     */
    public function action(): AuditAction
    {
        return $this->action;
    }

    /**
     * Returns the typed primary subject
     */
    public function getSubject(): AuditSubject
    {
        return $this->subject;
    }

    /**
     * Returns the trusted outcome occurrence time
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Returns actual message or scoped operation correlation when available
     */
    public function getCorrelation(): ?AuditCorrelation
    {
        return $this->correlation;
    }

    /**
     * Returns admitted action-specific facts rather than arbitrary context
     */
    public function getFacts(): AuditUserOutcome|AuditGrantOutcome|AuditAgentOutcome
        |AuditSessionRevocation|AuditRoleRevisions|AuditFeatureOutcome|AuditPermissionOutcome|AuditRolePermissionOutcome
    {
        return $this->facts;
    }

    /**
     * Returns the complete current persisted contract, not an authorized public representation
     *
     * @phpstan-return EvidencePayload
     */
    public function toArray(): array
    {
        $kind = AuditActorKind::forId($this->actorId)->value;

        return [
            'id'          => $this->id->toString(),
            'actor'       => ['kind' => $kind, 'id' => $this->actorId->toString()],
            'subject'     => $this->subject->toArray(),
            'action'      => $this->action->value,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'correlation' => $this->correlation?->toArray(),
            'facts'       => $this->facts->toArray()
        ];
    }

    /**
     * Validates the admitted User action and its required grant family and predecessor presence
     *
     * @throws DomainException When facts do not describe the declared action
     */
    private function validateUserOutcome(AuditUserOutcome $facts): void
    {
        $creation = in_array($this->action, [AuditAction::USER_INVITED, AuditAction::USER_REGISTERED], true);
        if ($creation !== ($facts->getBefore() === null)) {
            throw new DomainException('Audit User creation must not fabricate a predecessor.');
        }

        $grantKind = match ($this->action) {
            AuditAction::USER_INVITED, AuditAction::USER_REGISTERED,
            AuditAction::USER_PENDING_INVITATION_CORRECTED,
                AuditAction::USER_PENDING_REGISTRATION_RECOVERED => ActivationGrantId::class,
            AuditAction::PASSWORD_RESET_COMPLETED => PasswordResetGrantId::class,
            AuditAction::EMAIL_CHANGE_REQUESTED, AuditAction::EMAIL_CHANGE_CANCELLED,
                AuditAction::EMAIL_CHANGE_CONFIRMED => EmailChangeGrantId::class,
            AuditAction::USER_ENABLED, AuditAction::USER_DISABLED, AuditAction::USER_DELETED,
            AuditAction::USER_RESTORED, AuditAction::PASSWORD_CHANGED => null,
            default => throw new DomainException('Audit User facts do not admit this action.')
        };
        if (
            $grantKind !== null
            && ($facts->getGrantAfter() === null || $facts->getGrantAfter()->getId()::class !== $grantKind)
        ) {
            throw new DomainException('Audit outcome requires its actual grant and delivery relationship.');
        }
    }

    /**
     * Validates grant-only actions without implying a User mutation
     *
     * @throws DomainException When the grant family or predecessor does not match the action
     */
    private function validateGrantOutcome(AuditGrantOutcome $facts): void
    {
        $grantKind = match ($this->action) {
            AuditAction::INVITATION_RESENT, AuditAction::INVITATION_RETRY_REQUESTED,
            AuditAction::INVITATION_DELIVERY_FAILED,
                AuditAction::INVITATION_DELIVERY_CONFIRMED => ActivationGrantId::class,
            AuditAction::PASSWORD_RESET_REQUESTED, AuditAction::PASSWORD_RESET_DELIVERY_FAILED,
                AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED => PasswordResetGrantId::class,
            AuditAction::EMAIL_CHANGE_DELIVERY_FAILED,
                AuditAction::EMAIL_CHANGE_DELIVERY_CONFIRMED => EmailChangeGrantId::class,
            default => throw new DomainException('Audit grant facts do not admit this action.')
        };
        if (
            $facts->getAfter()->getId()::class !== $grantKind
            || ($facts->getBefore() !== null && $facts->getBefore()->getId()::class !== $grantKind)
            || ($facts->getBefore() === null && $this->action !== AuditAction::PASSWORD_RESET_REQUESTED)
        ) {
            throw new DomainException('Audit grant facts require the actual outcome family and predecessor.');
        }

        $validOutcome = match ($this->action) {
            AuditAction::INVITATION_DELIVERY_CONFIRMED, AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED,
                AuditAction::EMAIL_CHANGE_DELIVERY_CONFIRMED => (
                    $facts->getDeliveryStatus() === CredentialDeliveryStatus::DELIVERED
                ),
            AuditAction::INVITATION_DELIVERY_FAILED, AuditAction::PASSWORD_RESET_DELIVERY_FAILED,
                AuditAction::EMAIL_CHANGE_DELIVERY_FAILED => in_array($facts->getDeliveryStatus(), [
                    CredentialDeliveryStatus::RETRY_PENDING, CredentialDeliveryStatus::PERMANENT_FAILURE
                ], true),
            default => $facts->getDeliveryStatus() === null
        };
        if (!$validOutcome) {
            throw new DomainException('Audit delivery classification must match its declared outcome.');
        }
    }

    /**
     * Validates actual credential lifecycle and revision semantics without invented predecessor state
     *
     * @throws DomainException When credential states do not describe the declared outcome
     */
    private function validateAgentOutcome(AuditAgentOutcome $facts): void
    {
        $before = $facts->getBefore()?->toArray();
        $after = $facts->getAfter()->toArray();
        $valid = match ($this->action) {
            AuditAction::AGENT_PROVISIONED => $before === null && $after['state'] === AgentState::ACTIVE->value
                && $facts->getDeliveryId() !== null,
            AuditAction::AGENT_CREDENTIAL_ROTATED => $before !== null
                && $before['state'] === AgentState::ACTIVE->value && $after['state'] === AgentState::ACTIVE->value
                && $before['credential_id'] !== $after['credential_id']
                && $before['credential_revision'] < PHP_INT_MAX
                && $after['credential_revision'] === $before['credential_revision'] + 1
                && $facts->getDeliveryId() !== null,
            AuditAction::AGENT_CREDENTIAL_REVOKED => $before !== null
                && $before['state'] === AgentState::ACTIVE->value && $after['state'] === AgentState::REVOKED->value
                && $before['credential_id'] === $after['credential_id']
                && $before['credential_revision'] === $after['credential_revision'],
            default => false
        };
        if (!$valid) {
            throw new DomainException('Audit credential facts do not describe the declared outcome.');
        }

        if (
            $this->action !== AuditAction::AGENT_CREDENTIAL_REVOKED
            && $this->correlation?->getOperationKey() === null
        ) {
            throw new DomainException('Audit issuance requires its actual scoped operation key.');
        }
    }
}
