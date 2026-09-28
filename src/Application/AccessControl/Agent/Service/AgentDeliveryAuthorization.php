<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryAuthority;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Interface AgentDeliveryAuthorization
 *
 * Participates in the package transaction with authoritative worker, delegation, target and slot fences.
 */
interface AgentDeliveryAuthorization
{
    /**
     * Validates current authority and returns fenced epoch and expiry evidence using trusted decision time
     *
     * Authenticate the real worker and explicit delegation to original scope; check current Permission, target Agent,
     * destination owner/binding and exact reserved write version. Hold all fences through commit on the same connection
     * as operation and Agent persistence. Every consumer authority writer must participate; never cache an allow,
     * impersonate the originating caller, or begin/commit a nested transaction. Unsupported participation fails closed.
     * Epoch binds real worker plus all authorization revisions; revoke/regrant, reassignment and Permission changes
     * advance it irreversibly. Expiry is the earliest caller/delegation authorization deadline, not a new lease.
     * Throw sanitized AgentOperationRejectedException; actor strings and IDs alone are never authorization.
     */
    public function authorize(AgentIssuance $issuance, DateTimeImmutable $now): AgentDeliveryAuthority;
}
