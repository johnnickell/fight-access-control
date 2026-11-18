<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Exception;

/**
 * Interface AuditEvidenceRepository
 */
interface AuditEvidenceRepository
{
    /**
     * Adds admitted outcome evidence inside the caller's actual mutation transaction
     *
     * Join the same connection and enclosing transaction as the owning use case; do not independently commit,
     * intercept business repositories or reconstruct evidence from returned Events. Preserve the complete current
     * representation, including evidence identity, actor/subject kinds, native time precision, nullable predecessor
     * states, complete Role incarnation and scoped operation correlation. A transaction-active flag alone does not
     * qualify actual composition. Evidence is not automatically authorized for public responses or logs.
     *
     * A configured storage failure must abort pre-commit transactional work, never fall back to disabled audit.
     * Concurrent losers roll back their evidence. A lost commit acknowledgement is not rollback proof. Existing
     * retained-key resolution creates no new mutation record or backfilled history; record identity and correlation
     * do not establish universal deduplication. External effects and post-commit publication retain their own policy.
     *
     * Covered owners' approved optional composition uses a last nullable repository argument: omitted/null means
     * intentionally disabled, not a no-op repository or enable flag. With storage enabled, establish provenance
     * before the first mutation requiring evidence; a pure no-change or disabled path gains no audit-only actor
     * obligation. Family constructors/admission adopt that policy separately; this interface does not make existing
     * mandatory-wired owners optional or add outcomes to currently unaudited paths.
     *
     * @throws Exception When evidence cannot be staged in the owning transaction
     */
    public function add(AuditEvidence $evidence): void;
}
