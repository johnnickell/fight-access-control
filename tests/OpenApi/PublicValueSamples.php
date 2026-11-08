<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationProblem;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionPlanItem;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRolePlanItem;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PermissionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\SessionView;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/** Produces actual safe serialized values, never fabricated aggregate or sensitive-result serialization. */
final class PublicValueSamples
{
    /** @return iterable<string, array{string, mixed}> */
    public static function values(): iterable
    {
        $id = PublicMessageSamples::ID;
        $at = new DateTimeImmutable(PublicMessageSamples::TIME);
        $userId = UserId::fromString($id);
        $permissionId = PermissionId::fromString($id);
        $permissionName = PermissionName::fromString('TEST_FEATURES');
        $principalPermission = new PrincipalPermission($permissionId, $permissionName);
        yield 'PrincipalPermission' => ['PrincipalPermission', $principalPermission->toArray()];
        $views = [
            'User'       => new UserView(
                $userId,
                EmailAddress::fromString('operator@example.test'),
                UserState::ACTIVE,
                [RoleId::fromString($id)],
                $at,
                $at
            ),
            'Agent'      => new AgentView(
                AgentId::fromString($id),
                AgentName::fromString(' Operator '),
                AgentState::ACTIVE,
                AgentCredentialId::fromString($id),
                0,
                1,
                [$principalPermission]
            ),
            'Permission' => new PermissionView(
                $permissionId,
                $permissionName,
                PermissionTier::ADMIN_SAFE,
                true
            ),
            'Role'       => new RoleView(
                RoleId::fromString($id),
                RoleName::fromString('ROLE_OPERATORS'),
                false,
                [$permissionId],
                RoleRevision::fromString($id.':1')
            ),
            'Session'    => new SessionView(
                RefreshSessionId::fromString($id),
                $userId,
                $at,
                $at,
                $at->modify('+1 hour'),
                $at->modify('+1 day'),
                true,
                true
            ),
            'Feature'    => new FeatureView(
                FeatureId::fromString($id),
                FeatureName::fromString('dashboard'),
                FeatureStatus::PREVIEW,
                $permissionId,
                $permissionName,
                1
            )
        ];
        foreach ($views as $name => $view) {
            yield $name => [$name, $view->toArray()];
            yield $name.' envelope' => ['JSend.Success.'.$name, ['status' => 'success', 'data' => $view->toArray()]];
            $list = new ArrayList($view::class);
            $list->add($view);
            $page = new ResultSet(1, 10, 1, $list)->toArray();
            // ResultSet retains objects. A consumer projects each safe View, not arbitrary object JSON.
            $page['records'] = array_map(static fn($record): array => $record->toArray(), $page['records']);
            yield $name.' populated page' => [$name.'Collection', $page];
            yield $name.' out of range' => [$name.'Collection', new ResultSet(
                100,
                10,
                0,
                new ArrayList($view::class)
            )->toArray()];
        }

        yield 'AgentProfile' => ['AgentProfile', new AgentProfileView(
            AgentId::fromString($id),
            AgentName::fromString('Operator')
        )->toArray()];
        foreach (FeatureStatus::cases() as $status) {
            yield 'Feature '.$status->value.' missing binding' => ['Feature', new FeatureView(
                FeatureId::fromString($id),
                FeatureName::fromString('dashboard'),
                $status,
                $permissionId,
                null,
                1
            )->toArray()];
        }

        yield 'preparation empty' => ['FeaturePreparationResult', new FeaturePreparationResult()->toArray()];
        foreach (FeaturePreparationProblem::cases() as $problem) {
            $issue = new FeaturePreparationIssue(FeatureName::fromString('dashboard'), $problem);
            yield 'issue '.$problem->value => ['FeaturePreparationIssue', $issue->toArray()];
            yield 'preparation '.$problem->value => ['FeaturePreparationResult', new FeaturePreparationResult($issue)->toArray()];
        }

        $definition = new ManagedPermissionDefinition(
            $permissionId,
            $permissionName,
            PermissionTier::ADMIN_SAFE
        );
        $role = new ManagedRoleDefinition(
            RoleId::fromString($id),
            RoleName::fromString('ROLE_OPERATORS'),
            [$permissionId]
        );
        yield 'permission definition' => ['ManagedPolicy.PermissionDefinition', $definition->toArray()];
        yield 'role definition' => ['ManagedPolicy.RoleDefinition', $role->toArray()];
        foreach (ManagedPolicyChangeAction::cases() as $action) {
            $plan = new ManagedPolicyPlan(
                [new ManagedPermissionPlanItem($definition, $action)],
                [new ManagedRolePlanItem($role, $action)]
            );
            yield 'plan '.$action->value => ['ManagedPolicyPlan', $plan->toArray()];
        }

        $key = new AgentOperationKey(new AgentOperationScope(
            'consumer',
            'user',
            'operator'
        ), AgentOperationId::fromString($id));
        $destination = new AgentCredentialDestination(
            AgentDestinationId::fromString($id),
            1
        );
        $issuance = new AgentIssuance(
            $key,
            AgentDeliveryId::fromString($id),
            AgentId::fromString($id),
            AgentCredentialId::fromString($id),
            0,
            $destination,
            1,
            $at
        );
        yield 'operation key' => ['AgentOperationKey', $key->toArray()];
        yield 'issuance' => ['AgentIssuance', $issuance->toArray()];
        yield 'indeterminate' => ['AgentOperation', AgentOperationView::indeterminate($key)->toArray()];
        foreach (AgentDeliveryDisposition::cases() as $delivery) {
            foreach (AgentCredentialDisposition::cases() as $credential) {
                $view = AgentOperationView::confirmed(2, $issuance, $delivery, $credential);
                yield 'operation '.$delivery->value.' '.$credential->value => ['AgentOperation', $view->toArray()];
            }
        }

        $confirmed = AgentOperationView::confirmed(
            2,
            $issuance,
            AgentDeliveryDisposition::PENDING,
            AgentCredentialDisposition::CURRENT
        )->toArray();
        foreach (['DueAgentDeliveries', 'AgentDeliveryMaintenance'] as $name) {
            yield $name.' empty' => [$name, []];
            yield $name.' full' => [$name, array_fill(0, 100, $confirmed)];
        }

        yield 'maintenance count' => ['AgentDeliveryKeyReferences', 0];
        foreach (AgentMaintenanceResult::cases() as $result) {
            yield 'maintenance '.$result->value => ['AgentMaintenanceResult', $result->value];
        }

        foreach (AgentDeliveryResult::cases() as $result) {
            yield 'delivery '.$result->value => ['AgentDeliveryResult', $result->value];
        }

        $pending = PasswordResetDelivery::create(
            PasswordResetDeliveryId::fromString($id),
            $userId,
            EmailAddress::fromString('operator@example.test'),
            'test-only-encrypted-material',
            $at->modify('+1 hour'),
            $at
        );
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $pending->claim($token, $at, $at->modify('+5 minutes'));
        $deliveries = [$pending, $claimed, $claimed->confirm($token, $at), $claimed->failPermanently($token, $at),
            $pending->expireAt($at->modify('+1 hour')), $pending->invalidate()];
        foreach (CredentialDeliveryFailure::cases() as $failure) {
            if ($failure !== CredentialDeliveryFailure::PERMANENT_PROVIDER) {
                $deliveries[] = $claimed->fail($token, $at, $failure);
            }
        }

        foreach ($deliveries as $index => $delivery) {
            $status = CredentialDeliveryStatusView::fromDelivery('password_reset', $delivery, 0);
            yield 'status '.$index => ['CredentialDeliveryStatusResult', $status->toArray()];
            foreach (ActivationOrigin::cases() as $origin) {
                yield 'invitation '.$index.' '.$origin->value => ['InvitationDeliveryStatusResult',
                    new InvitationDeliveryStatusView(
                        $userId,
                        $delivery->getStatus(),
                        $delivery->getExpiresAt(),
                        ActivationDeliveryId::fromString($id),
                        $origin
                    )->toArray()];
            }
        }

        foreach (['CredentialDeliveryStatusResult', 'InvitationDeliveryStatusResult'] as $name) {
            yield $name.' absent' => [$name, new CredentialDeliveryAbsent()->toArray()];
        }

        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            $deliveryId = match ($purpose) {
                'activation' => ActivationDeliveryId::fromString($id),
                'password_reset' => PasswordResetDeliveryId::fromString($id),
                'email_change' => EmailChangeDeliveryId::fromString($id)
            };
            $due = new DueCredentialDelivery(
                $purpose,
                $deliveryId,
                $userId,
                $at,
                0,
                CredentialDeliveryStatus::PENDING
            );
            yield 'due '.$purpose => ['DueCredentialDelivery', $due->toArray()];
            yield 'due list '.$purpose => ['DueCredentialDeliveries', [$due->toArray()]];
            $expired = new ExpiredCredentialDelivery(
                $purpose,
                $deliveryId,
                $userId,
                $purpose === 'email_change' ? EmailChangeGrantId::fromString($id) : null,
                $at,
                0,
                CredentialDeliveryStatus::EXPIRED
            );
            yield 'expired '.$purpose => ['ExpiredCredentialDelivery', $expired->toArray()];
            yield 'expired list '.$purpose => ['ExpiredCredentialDeliveries', [$expired->toArray()]];
        }
    }
}
