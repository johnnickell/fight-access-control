---
id: TICKET-00028
epic: EPIC-00014
title: Revalidate Agent authority for protected continuation
status: ready-for-agent
---

# Revalidate Agent authority for protected continuation

## Problem and Outcome

`CurrentAgentPrincipalProvider` supplies an immutable snapshot for one authenticated request. Keeping it for a
whole stream/job freezes authority; constructing it afresh over the same signed request replays a consumed nonce.
Consumers need reusable trusted current-authority checks before new protected disclosures/continuations without
turning AccessControl into a stream manager or executor.

Separate original authentication/binding from authoritative revalidation at each protected live/background
boundary. Retain ordinary short-request snapshot semantics. This is item 4 of
[EPIC-00014](../epics/00014-EPIC.md) and the accepted freshness contract in
[WF-033](../wayfinder/tickets/WF-033-common-owned-mcp-permission-bindings.md).

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Consumer authenticates an ordinary signed request | Existing provider contract; no new business message | Preserve signature/replay admission, nonce transaction and one request-local snapshot. |
| Trusted consumer/runtime begins repeated protected work | N/A; trusted integration | Establish verifiable original Agent/credential context, not a caller-supplied identity or copied permission list. |
| Runtime is about to disclose another protected frame/item or continue work | No new business Command/Event; authoritative reads as required | Recheck current Agent status, original credential binding, applicable Permissions and consumer-supplied current scope. |
| Revocation, credential change, missing Permission or denied scope occurs | N/A | Deny further protected disclosure/continuation without switching identity/credentials or replaying authentication. |
| Authority resolution or scope verification fails | N/A | Fail closed with a safe integration outcome; never retain an earlier allow as fallback or emit private diagnostics. |

Revalidation does not create grants or mutate business aggregates. Authentication nonce consumption remains owned
by initial authentication. No new Domain event, per-frame nonce consume, job Command, scheduler or global authority
store is required merely to supply a permission check.

## Requirements, Validation and Permissions

- Accept only trusted context rooted in admitted authentication; define binding validity/lifetime and supported
  consumer handoff in downstream design. IDs, protocol metadata, heartbeat freshness or saved principals are not
  independently sufficient admission evidence.
- Revalidate active/current Agent status and the original credential identity/revision; rotation or replacement
  denies use of that old binding even when Agent identity remains the same. Never silently adopt new credentials.
- Resolve actual current direct Permission definitions/assignments for the protected operation. Do not reuse the
  original snapshot after changes, manufacture grants from revisions or infer authority from human Roles/OAuth scopes.
- Consumers re-evaluate application-specific current scope at the applicable boundary. The package does not learn
  project membership/Task sharing by accepting the target selector as an access grant.
- State precisely how fresh checks bind to protected admission/disclosure, including concurrent changes and
  failure; specify and prove synchronization/fence behavior without claiming impossible retroactive revocation.
- Runtime owners invoke checks before each new protected disclosure/background continuation, including queued
  frames and retained accepted-input consumption. This is not a periodic unconditional permission lease.
- Keep the ordinary request-local API semantics intact. A short request admitted before an authority change is
  not retroactively cancelled; a later ordinary request authenticates anew under its existing replay rules.
- Denial retracts no bytes, undoes no earlier effects, forces no job cancellation and establishes no rollback.
  Reconnect/retry requires the actual current authentication/authorization path; this TICKET supplies no executor.
- Keep credentials, signed requests, nonces, private causes and permission/scope explanations out of public output
  and unredacted logs. Use the neutral binding/protocol-specific failure contract, not a universal HTTP envelope.

## Dependencies and External Gates

- Builds on existing Agent/principal/Permission contracts, not a dependency on new Tool/Resource/Prompt bindings.
- [TICKET-00030](00030-TICKET.md) and [TICKET-00031](00031-TICKET.md) consume current checks where their live disclosure
  or protected continuation needs them. Their actual Common runtime ports are separate external prerequisites;
  do not couple this reusable contract to hypothetical method signatures.
- Native short-request HTTP work in [TICKET-00032](00032-TICKET.md) does not wait for live integration. If exposing
  repeated work, its consumer composition must invoke this contract at later boundaries, not reuse middleware admission.
- Actual persistence, cryptography, scope authorities, request/job lifetimes and races require consumer qualification;
  controlled package tests do not establish a production synchronization guarantee.
- Exact public interfaces, trusted binding representation and test arrangements are downstream design under the
  accepted guarantees and ADR 0011; no compatibility reader or credential migration is selected.

## Acceptance Evidence

- [ ] Real package authority/Permission resolution supports trusted initial binding and current rechecks without
  re-consuming a signed nonce, re-running the same authenticated request as new or trusting caller principal fields.
- [ ] Revoked Agent, changed original credentials, removed/missing Permission, changed consumer scope and resolver
  faults deny the next protected boundary, including queued disclosure and background continuation.
- [ ] Permission grants/changes and original credential checks observe the actual current state with documented
  race/admission semantics; there is no stale-principal singleton, permissive failure fallback or silent identity switch.
- [ ] Ordinary one-request success/failure snapshot and fresh-request nonce/authority behavior remain intact.
- [ ] Integration scenarios prove denial before protected disclosure/effect, not forced stop, compensation, retraction
  or rollback; public/diagnostic outcomes contain no credential or private permission/scope material.
- [ ] Tests/documentation, exact coverage, canonical gate and later independent Review/behavioral QA prove package
  behavior and explicitly identify the remaining actual-consumer lifetime/concurrency qualification.

## Exclusions

Stream/worker management, schedulers/executors, token/session issuers, automatic credential refresh, business
relationship policy, a project/Task authority store, per-frame authentication replay, forced cancellation and rollback.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 88 | [TASK-00088](../tasks/00088-TASK.md) | Revalidate bound Agent authority before protected continuation | ready-for-agent |
## Decisions and Progress

John approved item 4 of the complete eight-TICKET split, explicitly invoked To Tasks for this TICKET, then approved
one complete feature TASK: [TASK-00088 — Revalidate bound Agent authority before protected continuation](../tasks/00088-TASK.md).
Its full accepted decomposition is now written, keeping trusted binding and current checks together, including
check-to-admission synchronization, guarded queued/background/retained-input scenarios, unchanged ordinary requests,
safe outcomes, tests/docs/gates and independent Review/QA. No separate prefactor or verification-only TASK is planned.

TASK-00088 is ready-for-agent with no local TASK blocker or new Common runtime API prerequisite. Exact public
binding/check/fence contracts remain implementation design within accepted guarantees; actual consumer persistence,
scope, trusted handoff/lifetimes and races require separate qualification. Common live/Task integration remains
with downstream TICKET-00030/00031 rather than a dependency of this supplier.

This TICKET's TASK planning is complete, not its implementation. TICKET-00029's approved TASK-00089/00090 plan is now written; TICKET-00030's approved TASK-00091–00096 plan is now written; TICKET-00031's TASK-00097–00102 and TICKET-00032's approved TASK-00103–00106 plans are written.
All EPIC-00014 map planning phases/full TASK plans are accepted/complete; consult the Board for execution selection.
No source/dependency/config change, grant, implementation, publication, release or deployment is authorized by
planning approval. Preserve the combined release and Closed map; continue planning before Board-based execution selection.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
