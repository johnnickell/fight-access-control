<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Event;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler as Invitation;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler as Agent;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler as Email;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler as Feature;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler as Reset;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler\RevokeSessionHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler as Role;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler as User;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(Invitation\DeliverUserInvitationHandler::class)]
#[CoversClass(Invitation\ExpireInvitationDeliveryHandler::class)]
#[CoversClass(Invitation\ResendInvitationDeliveryHandler::class)]
#[CoversClass(Invitation\RetryInvitationDeliveryHandler::class)]
#[CoversClass(Agent\GrantPermissionToAgentHandler::class)]
#[CoversClass(Agent\RevokePermissionFromAgentHandler::class)]
#[CoversClass(Agent\ReplaceAgentPermissionsHandler::class)]
#[CoversClass(Agent\UpdateAgentHandler::class)]
#[CoversClass(Email\CancelEmailChangeHandler::class)]
#[CoversClass(Email\DeliverEmailChangeHandler::class)]
#[CoversClass(Email\ExpireEmailChangeHandler::class)]
#[CoversClass(Email\RequestEmailChangeHandler::class)]
#[CoversClass(Feature\CreateFeatureHandler::class)]
#[CoversClass(Feature\ProvisionFeaturesHandler::class)]
#[CoversClass(Feature\RemoveFeatureHandler::class)]
#[CoversClass(Feature\SetFeaturePermissionHandler::class)]
#[CoversClass(Feature\SetFeatureStatusHandler::class)]
#[CoversClass(ReconcileManagedPolicyHandler::class)]
#[CoversClass(Reset\DeliverPasswordResetHandler::class)]
#[CoversClass(Reset\ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(Reset\RequestPasswordResetHandler::class)]
#[CoversClass(RevokeSessionHandler::class)]
#[CoversClass(Role\CreateCustomRoleHandler::class)]
#[CoversClass(Role\GrantPermissionToCustomRoleHandler::class)]
#[CoversClass(Role\RemoveCustomRoleHandler::class)]
#[CoversClass(Role\RenameCustomRoleHandler::class)]
#[CoversClass(Role\RevokePermissionFromCustomRoleHandler::class)]
#[CoversClass(User\AssignRoleToUserHandler::class)]
#[CoversClass(User\CorrectPendingInvitationHandler::class)]
#[CoversClass(User\DeleteUserHandler::class)]
#[CoversClass(User\DisableUserHandler::class)]
#[CoversClass(User\EnableUserHandler::class)]
#[CoversClass(User\InvitePendingUserHandler::class)]
#[CoversClass(User\RecoverPendingRegistrationHandler::class)]
#[CoversClass(User\RegisterUserHandler::class)]
#[CoversClass(User\RemoveRoleFromUserHandler::class)]
#[CoversClass(User\RestoreUserHandler::class)]
final class HandlerFailureContractTest extends TestCase
{
    /** @return iterable<string, array{class-string<CommandHandler>, string, bool}> */
    public static function operations(): iterable
    {
        $operations = [
            Invitation\DeliverUserInvitationHandler::class    => 'User invitation delivery failed.',
            Invitation\ExpireInvitationDeliveryHandler::class => 'Credential expiry failed.',
            Invitation\ResendInvitationDeliveryHandler::class => 'Invitation resend failed.',
            Invitation\RetryInvitationDeliveryHandler::class  => 'Invitation delivery retry failed.',
            Agent\GrantPermissionToAgentHandler::class        => 'Agent permission grant failed.',
            Agent\RevokePermissionFromAgentHandler::class     => 'Agent permission revocation failed.',
            Agent\ReplaceAgentPermissionsHandler::class       => 'Agent permission replacement failed.',
            Agent\UpdateAgentHandler::class                   => 'Agent update failed.',
            Email\CancelEmailChangeHandler::class             => 'Email change cancellation failed.',
            Email\DeliverEmailChangeHandler::class            => 'Email change delivery failed.',
            Email\ExpireEmailChangeHandler::class             => 'Credential expiry failed.',
            Email\RequestEmailChangeHandler::class            => 'Email change request failed.',
            Feature\CreateFeatureHandler::class               => 'Feature creation failed.',
            Feature\ProvisionFeaturesHandler::class           => 'Feature provisioning failed.',
            Feature\RemoveFeatureHandler::class               => 'Feature removal failed.',
            Feature\SetFeaturePermissionHandler::class        => 'Feature permission update failed.',
            Feature\SetFeatureStatusHandler::class            => 'Feature status update failed.',
            ReconcileManagedPolicyHandler::class              => 'Managed policy reconciliation failed.',
            Reset\DeliverPasswordResetHandler::class          => 'Password reset delivery failed.',
            Reset\ExpirePasswordResetDeliveryHandler::class   => 'Credential expiry failed.',
            Reset\RequestPasswordResetHandler::class          => 'Password reset request failed.',
            RevokeSessionHandler::class                       => 'Session revocation failed.',
            Role\CreateCustomRoleHandler::class               => 'Custom role creation failed.',
            Role\GrantPermissionToCustomRoleHandler::class    => 'Custom role permission grant failed.',
            Role\RemoveCustomRoleHandler::class               => 'Custom role removal failed.',
            Role\RenameCustomRoleHandler::class               => 'Custom role rename failed.',
            Role\RevokePermissionFromCustomRoleHandler::class => 'Custom role permission revocation failed.',
            User\AssignRoleToUserHandler::class               => 'User role assignment failed.',
            User\CorrectPendingInvitationHandler::class       => 'Pending invitation correction failed.',
            User\DeleteUserHandler::class                     => 'User deletion failed.',
            User\DisableUserHandler::class                    => 'User disablement failed.',
            User\EnableUserHandler::class                     => 'User enablement failed.',
            User\InvitePendingUserHandler::class              => 'User invitation failed.',
            User\RecoverPendingRegistrationHandler::class     => 'Pending registration recovery failed.',
            User\RegisterUserHandler::class                   => 'User registration failed.',
            User\RemoveRoleFromUserHandler::class             => 'User role removal failed.',
            User\RestoreUserHandler::class                    => 'User restoration failed.'
        ];
        foreach ($operations as $handler => $message) {
            foreach ([false, true] as $notificationFails) {
                yield $handler.(int) $notificationFails => [$handler, $message, $notificationFails];
            }
        }
    }

    /** @param class-string<CommandHandler> $handlerClass */
    #[DataProvider('operations')]
    public function test_failure_notification_is_coarse_and_preserves_the_primary_outcome(
        string $handlerClass,
        string $message,
        bool $notificationFails
    ): void {
        $primary = new RuntimeException('Synthetic operation detail.');
        $attempts = [];
        $events = new InMemoryEventDispatcher(static function (Event $event) use (
            &$attempts,
            $notificationFails
        ): void {
            $attempts[] = $event;
            if ($notificationFails) {
                throw new RuntimeException('Synthetic notification failure.');
            }
        });
        $unitOfWork = $this->createMock(TransactionalUnitOfWork::class);
        $unitOfWork->expects(self::once())->method('commitTransactional')->willThrowException($primary);
        $handler = $this->handler($handlerClass, $unitOfWork, $events);
        $command = $this->command($handlerClass);
        $caught = null;
        try {
            $handler->handle(CommandMessage::create($command));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertCount(1, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
        self::assertSame($command, $attempts[0]->getCommand());
        $data = $attempts[0]->toArray();
        self::assertSame($message, $data['error_message']);
        self::assertSame($command->toArray(), $data['command_data']);
        self::assertSame($data, CommandFailedEvent::fromArray($data)->toArray());
        self::assertStringNotContainsString($primary->getMessage(), serialize($data));
    }

    /** @param class-string<CommandHandler> $handlerClass */
    private function command(string $handlerClass): Command
    {
        $id = '11111111-1111-4111-8111-111111111111';
        if ($handlerClass === ReconcileManagedPolicyHandler::class) {
            return new ReconcileManagedPolicy(new ManagedPolicy([], [], []));
        }

        $data = [
            'actor_id'                                => $id,
            'user_id'                                 => $id,
            'target_user_id'                          => $id,
            'agent_id'                                => $id,
            'role_id'                                 => $id,
            'permission_id'                           => $id,
            'permission_ids'                          => [],
            'expected_permission_assignment_revision' => 1,
            'feature_id'                              => $id,
            'expected_revision'                       => 1,
            'status'                                  => 'off',
            'email'                                   => 'person@example.test',
            'name'                                    => 'ROLE_EDITOR',
            'activation_delivery_id'                  => $id,
            'expected_activation_delivery_id'         => $id,
            'password_reset_delivery_id'              => $id,
            'email_change_delivery_id'                => $id,
            'email_change_grant_id'                   => $id,
            'occurred_at'                             => '2026-01-01T00:00:00.000000+00:00',
            'restoration_state'                       => 'pending_activation',
            'current_session_id'                      => $id,
            'target_session_id'                       => $id,
            'reason'                                  => null,
            'default_permission_name'                 => null,
            'initiator'                               => ['type' => 'user', 'id' => $id]
        ];
        if ($handlerClass === User\RegisterUserHandler::class) {
            $data = ['user_id' => $id, 'email' => 'person@example.test'];
        }

        if ($handlerClass === User\RecoverPendingRegistrationHandler::class) {
            $data = array_intersect_key($data, array_flip([
                'actor_id', 'user_id', 'expected_activation_delivery_id', 'email'
            ]));
        }

        if ($handlerClass === Feature\CreateFeatureHandler::class) {
            $data['name'] = 'example-feature';
        }

        return $handlerClass::commandRegistration()::fromArray($data);
    }

    /** @param class-string<CommandHandler> $handlerClass */
    private function handler(
        string $handlerClass,
        TransactionalUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): CommandHandler {
        $sessions = $this->createStub(RefreshSessionRepository::class);
        $permissions = $this->createStub(PermissionRepository::class);
        $roles = $this->createStub(RoleRepository::class);
        $users = $this->createStub(UserRepository::class);
        $agents = $this->createStub(AgentRepository::class);
        $discovery = $this->createStub(FeatureReferenceDiscovery::class);
        $discovery->method('discover')->willReturn(FeatureDiscoveryResult::complete(
            FeatureReferenceScope::CANDIDATE,
            new FeatureReferences()
        ));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $activation = $this->createStub(ActivationGrantRepository::class);
        $reset = $this->createStub(PasswordResetGrantRepository::class);
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $audit = $this->createStub(AuditEvidenceRepository::class);
        $credentials = $this->createStub(ActivationCredentialGenerator::class);
        $cipher = $this->createStub(InvitationDeliveryCipher::class);
        $provider = $this->createStub(CredentialDeliveryProvider::class);
        $features = $this->createStub(FeatureRepository::class);

        return match ($handlerClass) {
            Invitation\DeliverUserInvitationHandler::class => new Invitation\DeliverUserInvitationHandler(
                $activation,
                $audit, $unitOfWork, $cipher, $provider, $clock, $events
            ),
            Invitation\ExpireInvitationDeliveryHandler::class => new Invitation\ExpireInvitationDeliveryHandler(
                $activation,
                $unitOfWork, $events
            ),
            Invitation\ResendInvitationDeliveryHandler::class => new Invitation\ResendInvitationDeliveryHandler(
                $activation,
                $audit, $unitOfWork, $credentials, $cipher, $clock, $events
            ),
            Invitation\RetryInvitationDeliveryHandler::class => new Invitation\RetryInvitationDeliveryHandler(
                $activation,
                $audit, $unitOfWork, $clock, $events
            ),
            Agent\GrantPermissionToAgentHandler::class => new Agent\GrantPermissionToAgentHandler(
                $agents,
                $permissions, $clock, $unitOfWork, $events
            ),
            Agent\RevokePermissionFromAgentHandler::class => new Agent\RevokePermissionFromAgentHandler(
                $agents,
                $permissions, $clock, $unitOfWork, $events
            ),
            Agent\ReplaceAgentPermissionsHandler::class => new Agent\ReplaceAgentPermissionsHandler(
                $agents,
                $permissions, $clock, $unitOfWork, $events
            ),
            Agent\UpdateAgentHandler::class => new Agent\UpdateAgentHandler($agents, $clock, $unitOfWork, $events),
            Email\CancelEmailChangeHandler::class => new Email\CancelEmailChangeHandler(
                $users,
                $email, $this->createStub(EmailChangeAdministrationAuthorization::class),
                $audit, $unitOfWork, $clock, $events
            ),
            Email\DeliverEmailChangeHandler::class => new Email\DeliverEmailChangeHandler(
                $email,
                $audit, $unitOfWork, $this->createStub(EmailChangeDeliveryCipher::class), $provider, $clock, $events
            ),
            Email\ExpireEmailChangeHandler::class => new Email\ExpireEmailChangeHandler(
                $users,
                $email, $unitOfWork, $events
            ),
            Email\RequestEmailChangeHandler::class => new Email\RequestEmailChangeHandler(
                $users,
                $email, $this->createStub(EmailChangeAdministrationAuthorization::class), $audit, $unitOfWork,
                $this->createStub(EmailChangeCredentialGenerator::class),
                $this->createStub(EmailChangeDeliveryCipher::class), $clock, $events
            ),
            Feature\CreateFeatureHandler::class => new Feature\CreateFeatureHandler(
                $features,
                $permissions, $unitOfWork, $events
            ),
            Feature\SetFeaturePermissionHandler::class => new Feature\SetFeaturePermissionHandler(
                $features,
                $permissions, $unitOfWork, $events
            ),
            Feature\ProvisionFeaturesHandler::class => new Feature\ProvisionFeaturesHandler(
                $discovery,
                $features, $permissions, $unitOfWork, $events
            ),
            Feature\RemoveFeatureHandler::class => new Feature\RemoveFeatureHandler(
                $discovery,
                $features, $unitOfWork, $events
            ),
            Feature\SetFeatureStatusHandler::class => new Feature\SetFeatureStatusHandler(
                $features,
                $unitOfWork,
                $events
            ),
            ReconcileManagedPolicyHandler::class => new ReconcileManagedPolicyHandler(
                $permissions,
                $roles, new ManagedPolicyPlanner($permissions, $roles, $users, $agents),
                $unitOfWork, $events, $clock
            ),
            Reset\DeliverPasswordResetHandler::class => new Reset\DeliverPasswordResetHandler(
                $reset,
                $audit, $unitOfWork, $this->createStub(PasswordResetDeliveryCipher::class), $provider, $clock, $events
            ),
            Reset\ExpirePasswordResetDeliveryHandler::class => new Reset\ExpirePasswordResetDeliveryHandler(
                $reset,
                $unitOfWork, $events
            ),
            Reset\RequestPasswordResetHandler::class => new Reset\RequestPasswordResetHandler(
                $users,
                $reset, $audit, $unitOfWork, $this->createStub(PasswordResetCredentialGenerator::class),
                $this->createStub(PasswordResetDeliveryCipher::class), $clock, $events
            ),
            RevokeSessionHandler::class => new RevokeSessionHandler(
                $sessions,
                $clock, $this->createStub(SessionAdministrationAuthorization::class), $audit, $unitOfWork, $events
            ),
            Role\CreateCustomRoleHandler::class => new Role\CreateCustomRoleHandler(
                $roles,
                $clock,
                $unitOfWork,
                $events
            ),
            Role\RenameCustomRoleHandler::class => new Role\RenameCustomRoleHandler(
                $roles,
                $clock,
                $unitOfWork,
                $events
            ),
            Role\GrantPermissionToCustomRoleHandler::class => new Role\GrantPermissionToCustomRoleHandler(
                $roles,
                $permissions, $clock, $unitOfWork, $events
            ),
            Role\RevokePermissionFromCustomRoleHandler::class => new Role\RevokePermissionFromCustomRoleHandler(
                $roles,
                $permissions, $clock, $unitOfWork, $events
            ),
            Role\RemoveCustomRoleHandler::class => new Role\RemoveCustomRoleHandler(
                $roles,
                $users, $clock, $unitOfWork, $events
            ),
            User\AssignRoleToUserHandler::class => new User\AssignRoleToUserHandler(
                $users,
                $roles, $clock, $unitOfWork, $events
            ),
            User\RemoveRoleFromUserHandler::class => new User\RemoveRoleFromUserHandler(
                $users,
                $roles, $clock, $unitOfWork, $events
            ),
            User\CorrectPendingInvitationHandler::class => new User\CorrectPendingInvitationHandler(
                $users,
                $activation, $this->createStub(InvitationAdministrationAuthorization::class),
                $audit, $unitOfWork, $credentials, $cipher, $clock, $events
            ),
            User\DeleteUserHandler::class => new User\DeleteUserHandler(
                $users,
                new SessionRevocationService($sessions), $clock, $audit, $unitOfWork, $events
            ),
            User\DisableUserHandler::class => new User\DisableUserHandler(
                $users,
                new SessionRevocationService($sessions), $clock, $audit, $unitOfWork, $events
            ),
            User\EnableUserHandler::class => new User\EnableUserHandler($users, $audit, $unitOfWork, $events, $clock),
            User\InvitePendingUserHandler::class => new User\InvitePendingUserHandler(
                $users,
                $activation, $audit, $unitOfWork, $credentials, $cipher, $clock, $events
            ),
            User\RecoverPendingRegistrationHandler::class => new User\RecoverPendingRegistrationHandler(
                $users,
                $activation, $this->createStub(RegistrationRecoveryAuthorization::class),
                $audit, $unitOfWork, $credentials, $cipher, $clock, $events
            ),
            User\RegisterUserHandler::class => new User\RegisterUserHandler(
                $users,
                $roles, $activation, $audit, $unitOfWork, $credentials, $cipher, $clock, $events, ['ROLE_MEMBER']
            ),
            User\RestoreUserHandler::class => new User\RestoreUserHandler(
                $users,
                $activation, $credentials, $cipher, $clock, $audit, $unitOfWork, $events
            ),
            default => throw new LogicException('Unsupported handler fixture.')
        };
    }
}
