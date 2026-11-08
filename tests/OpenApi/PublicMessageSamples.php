<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Query\Query;

/** Explicit consumer samples, independent of the generated schemas. */
final class PublicMessageSamples
{
    public const string ID = '11111111-1111-4111-8111-111111111111';

    public const string TIME = '2026-11-08T12:00:00.123456+00:00';

    /** @return iterable<string, array{class-string<Command|Query>, array<string, mixed>}> */
    public static function messages(): iterable
    {
        $values = [
            'actor_id'                                => self::ID,
            'user_id'                                 => self::ID,
            'target_user_id'                          => self::ID,
            'agent_id'                                => self::ID,
            'permission_id'                           => self::ID,
            'role_id'                                 => self::ID,
            'feature_id'                              => self::ID,
            'activation_delivery_id'                  => self::ID,
            'expected_activation_delivery_id'         => self::ID,
            'email_change_delivery_id'                => self::ID,
            'email_change_grant_id'                   => self::ID,
            'password_reset_delivery_id'              => self::ID,
            'current_session_id'                      => self::ID,
            'target_session_id'                       => self::ID,
            'email'                                   => 'operator@example.test',
            'name'                                    => 'dashboard',
            'restoration_state'                       => 'active',
            'reason'                                  => null,
            'occurred_at'                             => self::TIME,
            'at'                                      => self::TIME,
            'page'                                    => 1,
            'per_page'                                => 10,
            'orderings'                               => ['name' => 'ASC'],
            'permission_ids'                          => [self::ID],
            'expected_permission_assignment_revision' => 1,
            'namespace'                               => 'consumer',
            'caller_type'                             => 'user',
            'caller_id'                               => 'operator',
            'operation_id'                            => self::ID,
            'destination_id'                          => self::ID,
            'destination_revision'                    => 1,
            'expected_credential_id'                  => self::ID,
            'expected_credential_revision'            => 0,
            'initiator'                               => ['type' => 'user', 'id' => self::ID],
            'purpose'                                 => 'activation',
            'delivery_id'                             => self::ID,
            'limit'                                   => 50,
            'work'                                    => 'material',
            'batch_size'                              => 50,
            'cleanup_grace_seconds'                   => 86400,
            'after'                                   => null,
            'key_version'                             => 'wrapping-v2',
            'default_permission_name'                 => null,
            'permissions'                             => [['id' => self::ID, 'name' => 'TEST_FEATURES', 'tier' => 'ADMIN_SAFE']],
            'roles'                                   => [['id' => self::ID, 'name' => 'ROLE_OPERATORS', 'permission_ids' => [self::ID]]],
            'referenced_permission_ids'               => [self::ID]
        ];
        $specifications = [
            'ActivationGrant/Command/DeliverUserInvitation'                  => 'actor_id user_id activation_delivery_id',
            'ActivationGrant/Command/ExpireInvitationDelivery'               => 'actor_id user_id activation_delivery_id occurred_at',
            'ActivationGrant/Command/ResendInvitationDelivery'               => 'actor_id user_id',
            'ActivationGrant/Command/RetryInvitationDelivery'                => 'actor_id user_id',
            'Agent/Command/GrantPermissionToAgent'                           => 'actor_id agent_id permission_id',
            'Agent/Command/ProvisionAgent'                                   => 'namespace caller_type caller_id operation_id name destination_id destination_revision',
            'Agent/Command/ReplaceAgentPermissions'                          => 'actor_id agent_id expected_permission_assignment_revision permission_ids',
            'Agent/Command/RevokePermissionFromAgent'                        => 'actor_id agent_id permission_id',
            'Agent/Command/RotateAgentCredential'                            => 'namespace caller_type caller_id operation_id agent_id expected_credential_id expected_credential_revision destination_id destination_revision',
            'Agent/Command/UpdateAgent'                                      => 'initiator agent_id name',
            'EmailChangeGrant/Command/CancelEmailChange'                     => 'actor_id user_id',
            'EmailChangeGrant/Command/DeliverEmailChange'                    => 'actor_id user_id email_change_delivery_id',
            'EmailChangeGrant/Command/ExpireEmailChange'                     => 'actor_id user_id email_change_grant_id occurred_at',
            'EmailChangeGrant/Command/RequestEmailChange'                    => 'actor_id user_id email',
            'Feature/Command/CreateFeature'                                  => 'name permission_id',
            'Feature/Command/ProvisionFeatures'                              => 'default_permission_name',
            'Feature/Command/RemoveFeature'                                  => 'feature_id expected_revision',
            'Feature/Command/SetFeaturePermission'                           => 'feature_id permission_id expected_revision',
            'Feature/Command/SetFeatureStatus'                               => 'feature_id status expected_revision',
            'ManagedPolicy/Command/ReconcileManagedPolicy'                   => 'permissions roles referenced_permission_ids',
            'PasswordResetGrant/Command/DeliverPasswordReset'                => 'actor_id user_id password_reset_delivery_id',
            'PasswordResetGrant/Command/ExpirePasswordResetDelivery'         => 'actor_id user_id password_reset_delivery_id occurred_at',
            'PasswordResetGrant/Command/RequestPasswordReset'                => 'email',
            'RefreshSession/Command/RevokeSession'                           => 'actor_id current_session_id target_session_id reason',
            'Role/Command/CreateCustomRole'                                  => 'actor_id role_id name',
            'Role/Command/GrantPermissionToCustomRole'                       => 'actor_id role_id permission_id',
            'Role/Command/RemoveCustomRole'                                  => 'actor_id role_id expected_revision',
            'Role/Command/RenameCustomRole'                                  => 'actor_id role_id name expected_revision',
            'Role/Command/RevokePermissionFromCustomRole'                    => 'actor_id role_id permission_id',
            'User/Command/AssignRoleToUser'                                  => 'actor_id target_user_id role_id',
            'User/Command/CorrectPendingInvitation'                          => 'actor_id user_id email',
            'User/Command/DeleteUser'                                        => 'actor_id user_id',
            'User/Command/DisableUser'                                       => 'actor_id user_id',
            'User/Command/EnableUser'                                        => 'actor_id user_id',
            'User/Command/InvitePendingUser'                                 => 'actor_id user_id email',
            'User/Command/RecoverPendingRegistration'                        => 'actor_id user_id expected_activation_delivery_id email',
            'User/Command/RegisterUser'                                      => 'user_id email',
            'User/Command/RemoveRoleFromUser'                                => 'actor_id target_user_id role_id',
            'User/Command/RestoreUser'                                       => 'actor_id user_id restoration_state',
            'ActivationGrant/Query/FindInvitationDeliveryStatus'             => 'user_id',
            'Agent/Query/GetAgentById'                                       => 'agent_id',
            'Agent/Query/GetAgentProfile'                                    => 'agent_id',
            'Agent/Query/ListAgents'                                         => 'page per_page orderings',
            'AgentCredentialOperation/Query/CountAgentDeliveryKeyReferences' => 'key_version',
            'AgentCredentialOperation/Query/GetAgentOperation'               => 'namespace caller_type caller_id operation_id destination_id destination_revision',
            'AgentCredentialOperation/Query/ListAgentDeliveryMaintenance'    => 'namespace caller_type caller_id destination_id destination_revision work batch_size cleanup_grace_seconds after',
            'AgentCredentialOperation/Query/ListDueAgentDeliveries'          => 'namespace caller_type caller_id destination_id destination_revision limit',
            'CredentialDelivery/Query/FindCredentialDeliveryStatus'          => 'purpose delivery_id',
            'CredentialDelivery/Query/FindDueCredentialDeliveries'           => 'at limit',
            'CredentialDelivery/Query/FindExpiredCredentialDeliveries'       => 'at limit',
            'Feature/Query/GetFeatureById'                                   => 'feature_id',
            'Feature/Query/ListFeatures'                                     => 'page per_page orderings',
            'Feature/Query/ValidateFeaturePreparation'                       => '',
            'ManagedPolicy/Query/PreviewManagedPolicy'                       => 'permissions roles referenced_permission_ids',
            'Permission/Query/GetPermissionById'                             => 'permission_id',
            'Permission/Query/ListPermissions'                               => 'page per_page orderings',
            'RefreshSession/Query/ListActiveSessions'                        => 'actor_id user_id current_session_id page per_page orderings',
            'Role/Query/GetRoleById'                                         => 'role_id',
            'Role/Query/ListRoles'                                           => 'page per_page orderings',
            'User/Query/GetUserById'                                         => 'user_id',
            'User/Query/ListUsers'                                           => 'page per_page orderings'
        ];
        foreach ($specifications as $path => $fields) {
            $input = $values;
            $input['expected_revision'] = 1;
            $input['status'] = 'preview';
            if (str_starts_with($path, 'Role/')) {
                $input['name'] = 'ROLE_OPERATORS';
                $input['expected_revision'] = self::ID.':1';
            }

            $payload = array_intersect_key($input, array_flip($fields === '' ? [] : explode(' ', $fields)));
            $class = 'Fight\\AccessControl\\Domain\\AccessControl\\'.str_replace('/', '\\', $path);
            yield basename($path) => [$class, $payload];
        }
    }
}
