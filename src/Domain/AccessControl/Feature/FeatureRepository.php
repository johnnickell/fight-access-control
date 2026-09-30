<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;

/**
 * Interface FeatureRepository
 *
 * Defines authoritative Feature storage participating in the caller's single transactional Unit of Work.
 */
interface FeatureRepository
{
    /**
     * Adds a new Feature without replacing any existing identity or name
     *
     * Enforce unique identity and name atomically, not only through prior lookup. Validate the referenced Permission
     * under the same transaction-duration reference fence used by all Permission removals and Feature reference
     * writers. Hold that protection through commit: a concurrent removal and referencing creation cannot both win.
     * Any existing Permission tier is eligible; a Feature reference is not an authority grant or promotion guard.
     * Rollback removes only this transaction's writes, never a concurrent winner. Reject rather than upsert/reset.
     *
     * @throws FeatureConflictException When the identity or name is already present
     * @throws FeatureReferenceException When the Permission reference cannot be established safely
     */
    public function add(Feature $feature): void;

    /**
     * Returns the stored Feature by stable identity or null when authoritatively absent
     */
    public function getById(FeatureId $id): ?Feature;

    /**
     * Returns the stored Feature by exact name or null when authoritatively absent
     *
     * A fresh provisioning pass must consult authoritative state, not a previous pass's negative lookup cache.
     * Preserve stored settings and broken reference identities; lookup does not certify readiness or availability.
     */
    public function getByName(FeatureName $name): ?Feature;
}
