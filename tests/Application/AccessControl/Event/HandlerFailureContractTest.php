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
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
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
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Meta;
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
    /** @return iterable<string, array{class-string<CommandHandler>, string, bool, bool}> */
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
                foreach ([false, true] as $dispatch) {
                    yield $handler.(int) $notificationFails.(int) $dispatch => [
                        $handler, $message, $notificationFails, $dispatch
                    ];
                }
            }
        }
    }

    /** @param class-string<CommandHandler> $handlerClass */
    #[DataProvider('operations')]
    public function test_failure_notification_is_coarse_and_preserves_the_primary_outcome(
        string $handlerClass,
        string $message,
        bool $notificationFails,
        bool $dispatch
    ): void {
        $primary = new RuntimeException('Synthetic operation detail.');
        $attempts = [];
        $eventDispatcher = new InMemoryEventDispatcher(static function (Event $event) use (
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
        $handler = $this->handler($handlerClass, $unitOfWork, $eventDispatcher);
        $command = $this->command($handlerClass);
        $original = CommandMessage::create($command);
        $replaced = $original->withMeta(Meta::create(['correlation' => 'command-contract', 'replace' => false]));
        $merged = $replaced->mergeMeta(Meta::create(['replace' => true, 'attempt' => 2]));
        self::assertSame([], $original->meta()->toArray());
        self::assertSame(['correlation' => 'command-contract', 'replace' => false], $replaced->meta()->toArray());
        self::assertSame($original->id(), $merged->id());
        self::assertSame($original->timestamp(), $merged->timestamp());
        self::assertSame($command, $merged->payload());
        $decoded = CommandMessage::arrayDeserialize($merged->arraySerialize());
        self::assertSame($merged->arraySerialize(), $decoded->arraySerialize());
        self::assertInstanceOf($handlerClass::commandRegistration(), $decoded->payload());
        self::assertSame($command->toArray(), $decoded->payload()->toArray());
        self::assertSame(
            ['correlation' => 'command-contract', 'replace' => true, 'attempt' => 2],
            $decoded->meta()->toArray()
        );
        $commandBus = $this->commandBus($handler);
        $caught = null;
        try {
            if ($dispatch) {
                $commandBus->dispatch($decoded);
            } else {
                $commandBus->execute($command);
            }
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertCount(1, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
        self::assertSame($dispatch ? $decoded->payload() : $command, $attempts[0]->getCommand());
        $data = $attempts[0]->toArray();
        self::assertSame($message, $data['error_message']);
        self::assertSame($command->toArray(), $data['command_data']);
        self::assertSame($data, CommandFailedEvent::fromArray($data)->toArray());
        self::assertStringNotContainsString($primary->getMessage(), serialize($data));
    }

    private function commandBus(CommandHandler $handler): CommandBus
    {
        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        return new RoutingCommandBus($router);
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
            'actor_kind'                              => 'user',
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

        if (in_array($handlerClass, [Role\RenameCustomRoleHandler::class, Role\RemoveCustomRoleHandler::class], true)) {
            $data['expected_revision'] = $id.':1';
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
        InMemoryEventDispatcher $eventDispatcher
    ): CommandHandler {
        $refreshSessionRepository = $this->createStub(RefreshSessionRepository::class);
        $permissionRepository = $this->createStub(PermissionRepository::class);
        $roleRepository = $this->createStub(RoleRepository::class);
        $userRepository = $this->createStub(UserRepository::class);
        $agentRepository = $this->createStub(AgentRepository::class);
        $discovery = $this->createStub(FeatureReferenceDiscovery::class);
        $discovery->method('discover')->willReturn(FeatureDiscoveryResult::complete(
            FeatureReferenceScope::CANDIDATE,
            new FeatureReferences()
        ));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $activationGrantRepository = $this->createStub(ActivationGrantRepository::class);
        $passwordResetGrantRepository = $this->createStub(PasswordResetGrantRepository::class);
        $emailChangeGrantRepository = $this->createStub(EmailChangeGrantRepository::class);
        $auditEvidenceRepository = $this->createStub(AuditEvidenceRepository::class);
        $credentials = $this->createStub(ActivationCredentialGenerator::class);
        $cipher = $this->createStub(InvitationDeliveryCipher::class);
        $provider = $this->createStub(CredentialDeliveryProvider::class);
        $featureRepository = $this->createStub(FeatureRepository::class);

        return match ($handlerClass) {
            Invitation\DeliverUserInvitationHandler::class => new Invitation\DeliverUserInvitationHandler(
                $activationGrantRepository,
                $auditEvidenceRepository, $unitOfWork, $cipher, $provider, $clock, $eventDispatcher
            ),
            Invitation\ExpireInvitationDeliveryHandler::class => new Invitation\ExpireInvitationDeliveryHandler(
                $activationGrantRepository,
                $unitOfWork, $eventDispatcher
            ),
            Invitation\ResendInvitationDeliveryHandler::class => new Invitation\ResendInvitationDeliveryHandler(
                $activationGrantRepository,
                $auditEvidenceRepository, $unitOfWork, $credentials, $cipher, $clock, $eventDispatcher
            ),
            Invitation\RetryInvitationDeliveryHandler::class => new Invitation\RetryInvitationDeliveryHandler(
                $activationGrantRepository,
                $auditEvidenceRepository, $unitOfWork, $clock, $eventDispatcher
            ),
            Agent\GrantPermissionToAgentHandler::class => new Agent\GrantPermissionToAgentHandler(
                $agentRepository,
                $permissionRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            Agent\RevokePermissionFromAgentHandler::class => new Agent\RevokePermissionFromAgentHandler(
                $agentRepository,
                $permissionRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            Agent\ReplaceAgentPermissionsHandler::class => new Agent\ReplaceAgentPermissionsHandler(
                $agentRepository,
                $permissionRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            Agent\UpdateAgentHandler::class => new Agent\UpdateAgentHandler(
                $agentRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher
            ),
            Email\CancelEmailChangeHandler::class => new Email\CancelEmailChangeHandler(
                $userRepository,
                $emailChangeGrantRepository, $this->createStub(EmailChangeAdministrationAuthorization::class),
                $auditEvidenceRepository, $unitOfWork, $clock, $eventDispatcher
            ),
            Email\DeliverEmailChangeHandler::class => new Email\DeliverEmailChangeHandler(
                $emailChangeGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $this->createStub(EmailChangeDeliveryCipher::class),
                $provider,
                $clock,
                $eventDispatcher
            ),
            Email\ExpireEmailChangeHandler::class => new Email\ExpireEmailChangeHandler(
                $userRepository,
                $emailChangeGrantRepository, $unitOfWork, $eventDispatcher
            ),
            Email\RequestEmailChangeHandler::class => new Email\RequestEmailChangeHandler(
                $userRepository,
                $emailChangeGrantRepository,
                $this->createStub(EmailChangeAdministrationAuthorization::class),
                $auditEvidenceRepository,
                $unitOfWork,
                $this->createStub(EmailChangeCredentialGenerator::class),
                $this->createStub(EmailChangeDeliveryCipher::class),
                $clock,
                $eventDispatcher
            ),
            Feature\CreateFeatureHandler::class => new Feature\CreateFeatureHandler(
                $featureRepository,
                $permissionRepository, $unitOfWork, $eventDispatcher
            ),
            Feature\SetFeaturePermissionHandler::class => new Feature\SetFeaturePermissionHandler(
                $featureRepository,
                $permissionRepository, $unitOfWork, $eventDispatcher
            ),
            Feature\ProvisionFeaturesHandler::class => new Feature\ProvisionFeaturesHandler(
                $discovery,
                $featureRepository, $permissionRepository, $unitOfWork, $eventDispatcher
            ),
            Feature\RemoveFeatureHandler::class => new Feature\RemoveFeatureHandler(
                $discovery,
                $featureRepository, $unitOfWork, $eventDispatcher
            ),
            Feature\SetFeatureStatusHandler::class => new Feature\SetFeatureStatusHandler(
                $featureRepository,
                $unitOfWork,
                $eventDispatcher
            ),
            ReconcileManagedPolicyHandler::class => new ReconcileManagedPolicyHandler(
                $permissionRepository,
                $roleRepository,
                new ManagedPolicyPlanner($permissionRepository, $roleRepository, $userRepository, $agentRepository),
                $unitOfWork,
                $eventDispatcher,
                $clock
            ),
            Reset\DeliverPasswordResetHandler::class => new Reset\DeliverPasswordResetHandler(
                $passwordResetGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $this->createStub(PasswordResetDeliveryCipher::class),
                $provider,
                $clock,
                $eventDispatcher,
                $userRepository
            ),
            Reset\ExpirePasswordResetDeliveryHandler::class => new Reset\ExpirePasswordResetDeliveryHandler(
                $passwordResetGrantRepository,
                $unitOfWork, $eventDispatcher
            ),
            Reset\RequestPasswordResetHandler::class => new Reset\RequestPasswordResetHandler(
                $userRepository,
                $passwordResetGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $this->createStub(PasswordResetCredentialGenerator::class),
                $this->createStub(PasswordResetDeliveryCipher::class),
                $clock,
                $eventDispatcher
            ),
            RevokeSessionHandler::class => new RevokeSessionHandler(
                $refreshSessionRepository,
                $clock,
                $this->createStub(SessionAdministrationAuthorization::class),
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            ),
            Role\CreateCustomRoleHandler::class => new Role\CreateCustomRoleHandler(
                $roleRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher
            ),
            Role\RenameCustomRoleHandler::class => new Role\RenameCustomRoleHandler(
                $roleRepository,
                $clock,
                $unitOfWork,
                $eventDispatcher
            ),
            Role\GrantPermissionToCustomRoleHandler::class => new Role\GrantPermissionToCustomRoleHandler(
                $roleRepository,
                $permissionRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            Role\RevokePermissionFromCustomRoleHandler::class => new Role\RevokePermissionFromCustomRoleHandler(
                $roleRepository,
                $permissionRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            Role\RemoveCustomRoleHandler::class => new Role\RemoveCustomRoleHandler(
                $roleRepository,
                $userRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            User\AssignRoleToUserHandler::class => new User\AssignRoleToUserHandler(
                $userRepository,
                $roleRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            User\RemoveRoleFromUserHandler::class => new User\RemoveRoleFromUserHandler(
                $userRepository,
                $roleRepository, $clock, $unitOfWork, $eventDispatcher
            ),
            User\CorrectPendingInvitationHandler::class => new User\CorrectPendingInvitationHandler(
                $userRepository,
                $activationGrantRepository, $this->createStub(InvitationAdministrationAuthorization::class),
                $auditEvidenceRepository, $unitOfWork, $credentials, $cipher, $clock, $eventDispatcher
            ),
            User\DeleteUserHandler::class => new User\DeleteUserHandler(
                $userRepository,
                new SessionRevocationService($refreshSessionRepository),
                $clock,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher,
                $passwordResetGrantRepository
            ),
            User\DisableUserHandler::class => new User\DisableUserHandler(
                $userRepository,
                new SessionRevocationService($refreshSessionRepository),
                $clock,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher,
                $passwordResetGrantRepository
            ),
            User\EnableUserHandler::class => new User\EnableUserHandler(
                $userRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher,
                $clock
            ),
            User\InvitePendingUserHandler::class => new User\InvitePendingUserHandler(
                $userRepository,
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $credentials,
                $cipher,
                $clock,
                $eventDispatcher
            ),
            User\RecoverPendingRegistrationHandler::class => new User\RecoverPendingRegistrationHandler(
                $userRepository,
                $activationGrantRepository, $this->createStub(RegistrationRecoveryAuthorization::class),
                $auditEvidenceRepository, $unitOfWork, $credentials, $cipher, $clock, $eventDispatcher
            ),
            User\RegisterUserHandler::class => new User\RegisterUserHandler(
                $userRepository,
                $roleRepository,
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $credentials,
                $cipher,
                $clock,
                $eventDispatcher,
                ['ROLE_MEMBER']
            ),
            User\RestoreUserHandler::class => new User\RestoreUserHandler(
                $userRepository,
                $activationGrantRepository,
                $credentials,
                $cipher,
                $clock,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            ),
            default => throw new LogicException('Unsupported handler fixture.')
        };
    }
}
