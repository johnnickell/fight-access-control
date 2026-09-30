<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureBindingException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\TestCase;

/**
 * Reusable public-port availability scenarios. Bind real adapters to prove ORM freshness and process reuse;
 * the controlled binding proves only package behavior, not consumer database or authorization integration.
 */
abstract class FeatureAvailabilityConformance extends TestCase
{
    public function test_rechecks_with_one_evaluator_and_unchanged_principal_observe_status_and_binding(): void
    {
        $environment = $this->environment();
        $first = $environment->permission('FIRST');
        $second = $environment->permission('SECOND');
        $environment->setFeature('flag', FeatureStatus::OFF, $first);
        $evaluator = $environment->evaluator();
        $name = FeatureName::fromString('flag');
        $principal = new AuthenticatedAgentPrincipal(
            AgentId::generate(),
            AgentCredentialId::generate(),
            1,
            1,
            [new PrincipalPermission($first, PermissionName::fromString('FIRST'))]
        );

        self::assertFalse($evaluator->isAvailable($name, $principal));
        $environment->setFeature('flag', FeatureStatus::PREVIEW, $first);
        self::assertTrue($evaluator->isAvailable($name, $principal));
        $environment->setFeature('flag', FeatureStatus::PREVIEW, $second);
        self::assertFalse($evaluator->isAvailable($name, $principal));
        $environment->setFeature('flag', FeatureStatus::ON, $second);
        self::assertTrue($evaluator->isAvailable($name, null));
        $environment->setFeature('flag', FeatureStatus::OFF, $second);
        self::assertFalse($evaluator->isAvailable($name, $principal));
    }

    public function test_worker_reuse_and_admitted_work_require_a_new_explicit_check_to_observe_off(): void
    {
        $environment = $this->environment();
        $permission = $environment->permission('PREVIEW');
        $name = FeatureName::fromString('job');
        $environment->setFeature('job', FeatureStatus::ON, $permission);
        $evaluator = $environment->evaluator();
        $jobAdmitted = $evaluator->isAvailable($name, null);
        self::assertTrue($jobAdmitted);

        // No further check: a previously admitted job finishes; a later job checks the same service again.
        $environment->setFeature('job', FeatureStatus::OFF, $permission);
        // An admitted job does not invoke the evaluator again; the subsequent job must do so.
        self::assertFalse($evaluator->isAvailable($name, null));
    }

    public function test_unknown_or_broken_references_never_use_another_permission_with_the_same_name(): void
    {
        $environment = $this->environment();
        $old = PermissionId::generate();
        $new = $environment->permission('SAME');
        $environment->setFeature('broken', FeatureStatus::ON, $old);
        $evaluator = $environment->evaluator();
        $user = new AuthenticatedUserPrincipal(
            UserId::generate(),
            RefreshSessionId::generate(),
            1,
            [],
            [new PrincipalPermission($new, PermissionName::fromString('SAME'))]
        );
        try {
            $evaluator->isAvailable(FeatureName::fromString('unknown'), $user);
            self::fail('Unknown names are not OFF.');
        } catch (FeatureNotFoundException) {
        }

        $this->expectException(FeatureBindingException::class);
        $evaluator->isAvailable(FeatureName::fromString('broken'), $user);
    }

    abstract protected function environment(): FeatureAvailabilityEnvironment;
}
