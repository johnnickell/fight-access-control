<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

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
     * writers, including future rebinding and validated no-op updates. Hold that protection through commit: a
     * concurrent removal and referencing creation cannot both win. A losing write rejects a missing ID rather than
     * resolving a replacement Permission by name.
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
     * Each explicit availability check, including another check in the same request or the next worker job, must
     * observe current committed settings and binding. Adapters must bypass stale ORM identity-map and result caches.
     * Preserve stored settings and broken reference identities; lookup does not certify readiness or availability.
     * Hydrate invalid persisted definitions as FeatureStateException (including invalid typed fields); do not return
     * null for malformed rows or infrastructure failures. A preparation query must distinguish those from absence.
     */
    public function getByName(FeatureName $name): ?Feature;

    /**
     * Returns an authoritative page of Features with its total count
     *
     * @return ResultSet<Feature>
     */
    public function getAll(Pagination $pagination): ResultSet;

    /**
     * Replaces exactly the expected Feature state under the enclosing transaction
     *
     * Compare identity, name, binding, status and revision at the final write boundary, including a no-op.
     * Return false for missing or stale state. Require a same-identity successor with immutable name and a revision
     * increment of exactly one for a real change; an unchanged successor performs no write. Under the shared
     * Permission-reference fence held through commit, validate the selected Permission ID on every attempt,
     * including a valid no-op; reject missing IDs rather than repairing broken bindings implicitly. This fence
     * also protects against concurrent Permission removal. A failed transaction must roll back the replacement.
     *
     * @throws FeatureReferenceException When the selected Permission is not authoritative
     */
    public function replace(Feature $expected, Feature $replacement): bool;
}
