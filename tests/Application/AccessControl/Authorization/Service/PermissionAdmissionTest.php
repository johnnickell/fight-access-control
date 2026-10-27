<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretDecipher;
use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\RequestAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnostic;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PermissionDeclaration::class)]
#[CoversClass(AdmissionMode::class)]
#[CoversClass(PermissionAdmission::class)]
#[CoversClass(RequestAuthority::class)]
final class PermissionAdmissionTest extends TestCase
{
    public function test_explicit_modes_and_real_user_role_resolution(): void
    {
        $guard = new PermissionAdmission();
        self::assertNull($guard->admit(new PermissionDeclaration(AdmissionMode::PUBLIC), null));
        $environment = new AdmissionEnvironment();
        $authority = $environment->user();
        $context = $guard->admit($this->protected(), $authority);
        self::assertNotNull($context);
        self::assertTrue($context->hasRole(RoleName::fromString('ROLE_READER')));
        self::assertSame($context->getAuthenticatedAuthority(), $authority->resolve()->getAuthenticatedAuthority());
        self::assertNotNull($guard->admit(new PermissionDeclaration(AdmissionMode::AUTHENTICATED), $authority));
        self::assertSame(1, $environment->resolutions);
    }

    public function test_all_permissions_are_required_and_authenticated_does_not_grant_permissions(): void
    {
        $guard = new PermissionAdmission();
        $authority = new AdmissionEnvironment()->user(false);
        self::assertNotNull($guard->admit(new PermissionDeclaration(AdmissionMode::AUTHENTICATED), $authority));
        $this->expectException(PermissionDenied::class);
        $guard->admit($this->protected(), $authority);
    }

    public function test_agent_direct_authority_has_no_role_or_administrator_bypass_and_consumes_nonce_once(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = RequestAuthority::agent(
            $environment->provider,
            $environment->request,
            $environment->correlationId
        );
        $guard = new PermissionAdmission();
        $context = $guard->admit($this->protected(), $authority);
        self::assertNotNull($context);
        self::assertFalse($context->hasRole(RoleName::fromString('ROLE_SUPER_ADMIN')));
        $guard->admit($this->protected(), $authority);
        self::assertSame(1, $environment->nonces->consumptionCalls());
        $this->expectException(PermissionDenied::class);
        $guard->admit(new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ'), PermissionName::fromString('OTHER_PERMISSION')
        ]), $authority);
    }

    public function test_missing_declaration_is_configuration_failure(): void
    {
        $this->expectException(LogicException::class);
        new PermissionAdmission()->admit(null, null);
    }

    public function test_absent_authentication_is_explicit_refusal(): void
    {
        $this->expectException(AuthenticationRequired::class);
        new PermissionAdmission()->admit($this->protected(), null);
    }

    public function test_user_refusal_is_translated_by_type_without_disclosing_cause(): void
    {
        $this->expectException(AuthenticationRequired::class);
        new PermissionAdmission()->admit($this->protected(), new AdmissionEnvironment()->user(valid: false));
    }

    public function test_user_storage_fault_is_not_reclassified_from_message_or_code(): void
    {
        $fault = new RuntimeException('private sentinel authentication denied', 401);
        try {
            new AdmissionEnvironment()->user(fault: $fault)->resolve();
            self::fail('Storage failures must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
        }
    }

    public function test_agent_known_refusal_and_opaque_snapshot_or_resolution_failures_remain_distinct(): void
    {
        $environment = new ProfileToolEnvironment();
        foreach (AgentAuthenticationDiagnosticClassification::cases() as $classification) {
            $failure = new CurrentAgentPrincipalResolutionRejectedException(
                new AgentAuthenticationDiagnostic($classification, 'safe-correlation')
            );
            // Exercise the provider's actual classification seam via a controlled crypto boundary.
            $decipher = $this->createStub(
                HmacSharedSecretDecipher::class
            );
            $decipher->method('decrypt')->willThrowException($failure);
            $provider = new CurrentAgentPrincipalProvider(
                $environment->storage->agents,
                $environment->permissions,
                $decipher,
                new FixedHmacSignedAgentRequestVerifier(
                    $environment->request,
                    'original-test-secret'
                ),
                new FixedClock($environment->now()),
                $environment->nonces,
                $environment->storage->transaction
            );
            try {
                RequestAuthority::agent($provider, $environment->request, 'safe-correlation')->resolve();
                self::fail('The provider must fail.');
            } catch (AuthenticationRequired $translated) {
                self::assertContains($classification, [
                    AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
                    AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT
                ]);
                self::assertSame($failure, $translated->getPrevious());
            } catch (CurrentAgentPrincipalResolutionRejectedException $opaque) {
                self::assertContains($classification, [
                    AgentAuthenticationDiagnosticClassification::PERMISSION_SNAPSHOT_INVALID,
                    AgentAuthenticationDiagnosticClassification::RESOLUTION_FAILED
                ]);
                self::assertSame($failure, $opaque);
            }
        }
    }

    public function test_malformed_empty_conflicting_or_non_list_requirements_are_rejected(): void
    {
        $permission = PermissionName::fromString('AGENT_PROFILE_READ');
        foreach (
            [
            [AdmissionMode::PROTECTED, []],
            [AdmissionMode::PUBLIC, [$permission]],
            [AdmissionMode::AUTHENTICATED, [$permission]],
            [AdmissionMode::PROTECTED, ['AGENT_PROFILE_READ']],
            [AdmissionMode::PROTECTED, [$permission, $permission]],
            [AdmissionMode::PROTECTED, ['caller_key' => $permission]]
            ] as [$mode, $requirements]
        ) {
            try {
                new PermissionDeclaration($mode, $requirements);
                self::fail('Invalid declarations must not be admitted.');
            } catch (InvalidArgumentException $failure) {
                self::assertStringStartsWith('Invalid permission', $failure->getMessage());
            }
        }
    }

    private function protected(): PermissionDeclaration
    {
        return new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('AGENT_PROFILE_READ')]);
    }
}
