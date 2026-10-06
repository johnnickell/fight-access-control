<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

/**
 * Interface AgentOperationContractRepository
 *
 * Shares one authoritative cohort fence across Agent and operation persistence and every consumer authority writer.
 */
interface AgentOperationContractRepository
{
    /**
     * Retrieves current persisted cohort settings qualified against this worker's actual composition
     *
     * During a package transaction, acquire the shared cohort fence on the SAME connection and hold it through
     * commit/rollback, before acquiring subordinate authority, Agent, slot or key fences. Every writer, including
     * direct repository calls, must independently enforce this contract before effects. A cohort switch takes the
     * conflicting fence and advances a durable positive generation even on switch-back; never reset/reuse it.
     * Outside a transaction, read authoritative storage without mutation or commit; this grants no future admission.
     * Never cache startup readiness. Missing/unavailable storage, inconsistent settings, an unsupported local
     * participant or missing shared-connection participation throws sanitized UNAVAILABLE without a previous error.
     * No version defaults, bootstrap writes, keys, secrets, claims, events or provider diagnostics belong here.
     *
     * The snapshot's capabilities are the intersection of persisted qualification and the locally installed
     * repository/UoW/authorization/cipher/sink/maintenance contracts. Verify actual participants, not merely interface
     * names or an operator-supplied boolean. Runtime capability methods and current authorization still apply.
     * AgentRepository and AgentOperationRepository must observe the identical cohort and shared transaction.
     * Both repositories validate the single supported storage/canonical/destination contract and agree on its
     * generation. Unknown contract markers deny admission, never select a reader or migrate stored operations.
     * Retained keys and permanent tombstones keep their original request/issuance; safe status is not writer admission.
     * Consumer policy, Permission/tier, destination, key-admission and trusted-boundary writers participate too.
     * New-code checks cannot stop an unchecked old binary: revoke its storage/trusted-boundary access externally.
     *
     * Restoration also closes admission at a trusted boundary OUTSIDE the restored dataset before replacement.
     * Its reconciled generation must attest the exact active storage incarnation and complete current-contract
     * history: scoped keys/canonical requests, Agent authority, audit, receipts, tombstones, destination high-water
     * and key/authority fences. Missing, mismatched or unverifiable evidence supplies null, never the local generation.
     * Read this evidence freshly under the same transaction-duration fence; restoration/reconciliation and every
     * writer contend on it. Advance the non-restorable generation before re-admission, even for identical data.
     * Reconciliation preserves exact bindings and nondecreasing external history; no missing local receipt/event
     * proves absence of an external effect. A stale snapshot, deadline or epoch cannot reopen admission itself.
     * Consumers own quiesce, forward repair and actual storage/sink/activation fencing; this is no restore API or
     * automatic rollback detector. Safe reads/authentication do not prove reconciled state or activation/use authority.
     */
    public function getOperationContract(): AgentOperationContract;
}
