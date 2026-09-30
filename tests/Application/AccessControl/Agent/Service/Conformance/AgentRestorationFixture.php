<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

/**
 * Consumer-owned current-contract restore controls, not a package restore implementation
 *
 * Bind save/restore to real storage tools for consumer qualification. Retain independent trusted history and sink
 * receipts/tombstones/high-water outside the restored dataset. Restore closes admission under the conflicting cohort
 * fence BEFORE replacing data. Reconciliation validates the proposed forward-repair checkpoint against that retained
 * history and the live sink, then advances the trusted and persisted generation together with writers quiesced.
 * No method issues credentials, stages bytes, fabricates receipts, or authorizes activation/use.
 */
interface AgentRestorationFixture
{
    /** Saves a consistent current-contract package checkpoint, including cohort, Agent, operation, audit and order */
    public function savePackageState(string $checkpoint): void;

    /** Restores only package state, retaining external effects and invalidating trusted admission evidence */
    public function restorePackageState(string $checkpoint): void;

    /** Validates and applies authoritative forward repair, or leaves admission closed without any repair writes */
    public function reconcilePackageState(string $checkpoint): bool;

    /** Returns a stable equality token for all persisted package state without serializing or exposing secrets */
    public function packageStateFingerprint(): string;

    /** Removes or corrupts trusted evidence without changing restored package state or manufacturing sink history */
    public function breakRestorationEvidence(string $failure): void;
}
