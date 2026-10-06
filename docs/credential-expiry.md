# Human credential expiry cleanup (v0.5.0)

This current pre-v1 contract belongs to EPIC-00012 / TICKET-00020 / TASK-00072 and the planned `v0.5.0` integration.
It adds no scheduler, transport, production repository or authorization policy. Follow
[current delivery composition](credential-delivery.md) and [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md).
Package verification is not PostgreSQL qualification, consumer adoption or permission to publish a release.

## Protected composition

Protect **every** exposed query and dispatch entry point: scheduler, CLI, API, MCP and direct bus. A supplied actor ID
is trusted audit provenance only, never an authorization grant. Bind the three Domain grant repositories and the User
repository to the same `TransactionalUnitOfWork` connection. Register:

| Message | Handler | Exact dispatch identity |
| --- | --- | --- |
| `FindExpiredCredentialDeliveries(at, limit)` | `FindExpiredCredentialDeliveriesHandler` | Inclusive expiry boundary; limit 1–100, guidance 50 |
| `ExpireInvitationDelivery` | `ExpireInvitationDeliveryHandler` | Actor, User, `activation_delivery_id`, occurrence time |
| `ExpirePasswordResetDelivery` | `ExpirePasswordResetDeliveryHandler` | Actor, User, `password_reset_delivery_id`, occurrence time |
| `ExpireEmailChange` | `ExpireEmailChangeHandler` | Actor, User, **`email_change_grant_id`**, occurrence time |

Cleanup needs no cipher, key or provider capability. Bind those only to issuance/delivery handlers, not these cleanup
handlers. Do not decrypt old material to decide whether it is expired. Queries are advisory; commands own policy,
transactional rereads, authoritative-generation checks and complete-state CAS. The observed revision is diagnostic,
not a consumer policy predicate or command revision guess.

The query returns an unpaginated bounded list of immutable `ExpiredCredentialDelivery` values. Every canonical field
is required: `purpose`, `delivery_id`, `user_id`, nullable `email_change_grant_id`, `expires_at`, nonnegative `revision`
and safe delivery `status`. The grant ID is non-null exactly for `email_change`; purpose-specific delivery IDs and User
IDs are UUID values. Date-time serialization preserves microseconds in discovery and all three expiry commands/facts.
Payload timestamps require explicit RFC3339 instants (zero to six fractional digits); blank/relative timestamps and
calendar normalization such as February 30 are rejected, never interpreted as an inferred current boundary.
No credentials, hashes, ciphertext, destination, claim token or provider diagnostics are returned. Canonical round trips
reject missing identities, unsupported purposes and invalid bounds; the query requires an integer limit.

## Selection and repository obligations

All three grant repository interfaces now require `findExpired(DateTimeImmutable $at, int $limit): array` returning
`list<ExpiredCredentialDelivery>`. Reject bounds outside 1–100. Select eligibility **before** ordering and limiting:

- Invitation/reset: latest authoritative **issued** generation, recoverable pending/retry-pending/claimed delivery,
  delivery expiry at or before `at`. Due time and a claim lease cannot postpone cleanup.
- Email: latest authoritative **issued** grant at or past authority expiry, regardless of delivery status/material.
  Delivered, permanent-failure and already delivery-expired work still needs authority/reservation expiry.
- Exclude obsolete generations, consumed/revoked authority, fully expired email authority, and material-free
  invitation/reset delivery. Excluded history must not hide eligible work beyond the first page.

Each repository orders by expiry **instant**, delivery ID and purpose. The QueryHandler reads at most `limit` items
from each family, merges with the same order and returns at most `limit` globally. Equal instants in different timezones
compare equal; fractional boundaries are retained. There are no query writes, commits or business facts. Existing
`findDue()` selection, delivery leases and retry/backoff remain unchanged.

Every write compares the complete stored authoritative predecessor, not object identity, ID/revision alone or a
caller-fabricated state. Validate the exact next aggregate transition and preserve immutable IDs, digest, expiry,
ownership, attempts and previous outcome/failure history. Direct expiry of a reclaimed claim is not another provider
failure: compare the complete expected expiry state before considering a retry-failure terminalization. Persist material
removal and claim token/time/lease clearing in the same commit as terminal state; rollback restores the original state.

`EmailChangeGrant::issue()` now requires its seventh argument, the positive User reservation revision produced by the
request. Persist/hydrate this immutable binding on every grant revision, include it in complete-state comparisons and
preserve it across delivery, consumption, revocation and expiry. The package request handler supplies it. Matching the
same email string alone cannot authorize clearing a newer reservation (same-email ABA). This is a current-contract
integration change, not a compatibility default or a historical backfill requirement.

Email repository `replace()` accepts valid exact next delivery **or** authority transitions, not terminal authority only.
Full expiry matches grant/User/delivery ownership, destination and the bound reservation revision, then expires authority
and clears the User reservation in one shared transaction. A lost CAS on either side rolls back both. Recoverable delivery
becomes `invalidated`; material-free terminal delivery keeps its status and history. Only reservation revision and normal
User update metadata may change, in every reachable account state: active, disabled, deleted, enabled or restored to active
or pending activation. No canonical-email promotion, reactivation, password or authentication/authorization change occurs.
Request/cancellation retain their active-state policy. Release permits a fresh request with a fresh grant/reservation binding.

## Bounded runner and restart recipe

Agent OS TASK-00024 can compose the public contracts without copying lifecycle policy:

1. Authorize the runner and capture an explicit current boundary. Query expired work with limit 50 (or another 1–100 bound).
2. Dispatch each item's **exact** purpose-specific command with trusted actor provenance, its User ID, the returned
   delivery ID for invitation/reset or returned grant ID for email, and the captured occurrence time. Do not infer a grant
   ID from delivery ID, fetch secrets or independently clear a reservation.
3. For synchronous dispatch, wait for cleanup before requesting another page. Suggested runner defaults are 50 items
   per page and at most 10 pages (500 dispatches) per cycle; overrides must retain a finite budget. Stop on an empty page. Compare unchanged identities/revisions on the next page: if no progress occurred,
   stop this cycle and record sanitized unresolved-work evidence for investigation/retry rather than busy-spin. Never
   mark success merely because a void command returned. Do not silently omit or repair an inconsistent row to manufacture
   an empty page. A cycle budget bounds progress, not lifecycle eligibility.
4. For asynchronous scheduling, enqueue at most one bounded page per cycle and allow its transactions to finish before
   rescheduling. Dedupe pending dispatch by exact identity at the consumer boundary; do not spin on unclaimed/unchanged rows.
5. Run the unchanged due query/delivery path for still-live work. Its ciphertext/provider/claim responsibilities remain
   separate from cleanup. Do not invent a second retry queue or consumer expiry state machine.

Unknown/obsolete commands, early requests and terminal repeats are no-ops: no unchanged write or success fact.
Current expired email authority with a missing/mismatched User reservation is an inconsistency, not successful cleanup:
a safe exception and ordinary command-failure evidence identify unresolved work, leaving state unchanged. Stale-worker
outcomes and fabricated predecessors cannot resurrect bytes, overwrite a successor or clear an unrelated reservation.
CAS loss can require safe rediscovery, especially when account state changed; do not assume that a no-op proves destruction.

On write/commit failure the transaction rolls back; rediscover and retry the original identity. On an indeterminate commit
or lost response, restart from authoritative persisted state. A committed cleanup disappears from eligible discovery and
repeat dispatch makes no new write or expiry fact; a rolled-back cleanup remains discoverable. Never infer rollback from
missing notifications. Success facts follow commit. Publication failure retains ordinary command-failure/rethrow behavior,
not the scoped Agent issuance-warning exception, and cannot undo cleanup or guarantee later event replay. If failure
notification also throws, the original persistence/publication throwable still escapes; neither notification is guaranteed.
To satisfy the secret-free failure-message boundary, these three cleanup handlers publish the original safe command with
constant failure text `Credential expiry failed.`, not arbitrary repository diagnostics that may contain row material.
Consumers must sanitize the original rethrown throwable at response/log boundaries. This narrow redaction does not change
rollback, throw/rethrow or post-commit semantics, and is not the issuance-warning exception. No transactional
outbox/reliable-publication infrastructure or exactly-once event guarantee is added.

## Matrix and evidence boundary

[WF-025](../planning/wayfinder/tickets/WF-025-human-delivery-expiry-contract.md) and
[TICKET-00020](../planning/tickets/00020-TICKET.md) remain the complete acceptance authority. In the default suite,
`OfflineCredentialExpiryRegressionTest` reproduces reclaimed-history rejection and inactive-reservation expiry before
repair; `ExpiredCredentialRecoveryTest` exercises public queries/commands and modeled repository/persistence seams.

| Matrix | Required package disposition/proving seam |
| --- | --- |
| M01 | Pending/retry material cleanup; recoverable-state before/exact/after-expiry scenarios |
| M02 | First/abandoned claims lose all claim state without another attempt |
| M03 | Reclaimed history retained; saved failing regression followed by recovery/history assertions |
| M04 | Invitation/reset terminal delivery excluded; full email authority/reservation expiry still occurs |
| M05 | Delivery-only/backoff expiry cannot strand email authority; terminal delivery history unchanged |
| M06 | Consumed/revoked authority excluded; delayed stale outcomes lose complete-state CAS |
| M07 | Fully expired email authority excluded; repeat dispatch does not write or publish |
| M08 | Replacement/successor fencing and fresh same-email request with a new binding; old cleanup cannot retarget |
| M09 | All reachable account states retain account/security state; lifecycle/expiry CAS winner-order probes |
| M10 | Missing/mismatched/ABA relationships fail closed; unknown/stale identities are not fabricated success |
| M11 | Two workers and controlled complete-state CAS arbitration, both delivery/lifecycle winner orders |
| M12 | Failed commit, both uncertain outcomes, response loss and post-commit publication failure; persisted outcomes |

`CredentialDeliveryComponentsTest` checks shipped message/result schemas against actual payloads in the default suite,
not release-only tests. Exact statement coverage and complete `./bin/build` remain mandatory. Saved TASK evidence maps
specific tests/limits, gate results, warnings and any omissions; an implementation receipt cannot independently accept
its own technical review or behavioral QA.

Consumers still must qualify **actual PostgreSQL** shared-connection atomicity, isolation/fences, full-state CAS/hydration,
both competing-writer winner orders, failed writes/commit, uncertain commit and restart, bounded scheduler progress and
durable live-row destruction (including retained historical generations). Package object/reference tests do not certify
SQL adapters, physical erasure of backups/logs, key destruction, provider delivery or adoption. No Agent OS source/lock,
consumer product/email wiring, migration/backfill, old-release backport, tag, signing or deployment is part of this work.

The new repository obligations, mandatory reservation binding and public payload additions belong in the selected
pre-v1 minor `v0.5.0`, not an old-release patch. Certification and publication remain separate from version selection.
