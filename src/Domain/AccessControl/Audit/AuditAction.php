<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

/**
 * Enum AuditAction
 *
 * Names admitted package outcomes, not arbitrary caller descriptions.
 */
enum AuditAction: string
{
    case USER_INVITED = 'user.invited';
    case USER_REGISTERED = 'user.registered';
    case USER_PENDING_INVITATION_CORRECTED = 'user.pending_invitation_corrected';
    case USER_PENDING_REGISTRATION_RECOVERED = 'user.pending_registration_recovered';
    case USER_ENABLED = 'user.enabled';
    case USER_DISABLED = 'user.disabled';
    case USER_DELETED = 'user.deleted';
    case USER_RESTORED = 'user.restored';
    case INVITATION_RESENT = 'user.invitation_delivery.resent';
    case INVITATION_RETRY_REQUESTED = 'user.invitation_delivery.retry_requested';
    case INVITATION_DELIVERY_FAILED = 'user.invitation_delivery.failed';
    case INVITATION_DELIVERY_CONFIRMED = 'user.invitation_delivery.confirmed';
    case INVITATION_DELIVERY_EXPIRED = 'user.invitation_delivery.expired';
    case PASSWORD_RESET_REQUESTED = 'user.password_reset_requested';
    case PASSWORD_RESET_DELIVERY_FAILED = 'user.password_reset_delivery.failed';
    case PASSWORD_RESET_DELIVERY_CONFIRMED = 'user.password_reset_delivery.confirmed';
    case PASSWORD_RESET_DELIVERY_EXPIRED = 'user.password_reset_delivery.expired';
    case PASSWORD_CHANGED = 'user.password_changed';
    case PASSWORD_RESET_COMPLETED = 'user.password_reset_completed';
    case EMAIL_CHANGE_REQUESTED = 'user.email_change_administratively_requested';
    case EMAIL_CHANGE_CANCELLED = 'user.email_change_administratively_cancelled';
    case EMAIL_CHANGE_DELIVERY_FAILED = 'user.email_change_delivery.failed';
    case EMAIL_CHANGE_DELIVERY_CONFIRMED = 'user.email_change_delivery.confirmed';
    case EMAIL_CHANGE_CONFIRMED = 'user.email_change_confirmed';
    case SESSION_ADMINISTRATIVELY_REVOKED = 'refresh_session.administratively_revoked';
    case AGENT_PROVISIONED = 'agent.provisioned';
    case AGENT_CREDENTIAL_ROTATED = 'agent.credential_rotated';
    case AGENT_CREDENTIAL_REVOKED = 'agent.credential_revoked';
    case ROLE_CREATED = 'role.created';
    case ROLE_RENAMED = 'role.renamed';
    case ROLE_REMOVED = 'role.removed';
    case ROLE_PERMISSION_GRANTED = 'role.permission_granted';
    case ROLE_PERMISSION_REVOKED = 'role.permission_revoked';
    case PERMISSION_CREATED = 'permission.created';
    case PERMISSION_RECONCILED = 'permission.reconciled';
    case PERMISSION_REMOVED = 'permission.removed';
    case FEATURE_CREATED = 'feature.created';
    case FEATURE_STATUS_CHANGED = 'feature.status_changed';
    case FEATURE_PERMISSION_CHANGED = 'feature.permission_changed';
    case FEATURE_REMOVED = 'feature.removed';
}
