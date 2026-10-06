<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;

/**
 * Interface AgentMaintenanceAuthorization
 *
 * Authorizes the real maintainer independently of delivery, including obsolete original bindings.
 */
interface AgentMaintenanceAuthorization
{
    /**
     * Validates current read delegation before selection and again before each target disclosure
     *
     * No transaction, mutation or cached allow. Authorize historical destinations explicitly, not by possession of
     * IDs. With null issuance authorize only the scope/binding; otherwise also authorize the exact original target.
     */
    public function authorizeRead(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        DateTimeImmutable $now
    ): void;

    /**
     * Validates global key-reference accounting authority without granting physical retirement permission
     *
     * Check again after the authoritative global count. No scope-limited or paged count can qualify key retirement.
     */
    public function authorizeKeyAccounting(AgentDeliveryKeyVersion $version, DateTimeImmutable $now): void;

    /**
     * Validates current transactional maintenance authority before lookup and under exact target fences
     *
     * The authenticated worker needs explicit maintenance delegation/Permission over the original scope, target and
     * historical destination, even if obsolete/reassigned/revoked. A target key additionally needs current rewrap
     * authority and an open write-admission fence held through commit. Null target means expiry/terminal cleanup,
     * never physical key retirement or credential delivery. Hold shared authority/operation/lifecycle/key-reference
     * fences on the package connection; all writers participate. Reject nested/unsupported transactions. Return
     * real-worker/revision-bound epoch and earliest expiry; revoke/regrant advances epochs. No cached allow or actor
     * string is authority. No extra routine human approval. Throw sanitized rejection without previous exceptions.
     */
    public function authorize(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentIssuance $issuance,
        ?AgentDeliveryKeyVersion $target,
        DateTimeImmutable $now
    ): AgentDeliveryAuthority;
}
