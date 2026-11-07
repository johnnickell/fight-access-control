# Role and User membership contracts (Unreleased)

The five Role Commands and two User Role-assignment Commands execute through the configured Common Command bus.
Every handler returns `void`. A queued/accepted dispatch is not evidence of a committed mutation; asynchronous
consumers retain their own execution, reconciliation and transport policy. Actors are attribution, not grants.
Consumers authorize every HTTP, CLI, worker and direct bus entry, including no-ops, under
[ADR 0010](../planning/adr/0010-permission-eligibility-and-caller-authorization.md).

## Intentional outcomes

Application uses the supported Common `Application\Error\Exception` categories below. Domain exceptions remain
Domain-owned; only the known decision at its owning call is translated. Do not classify by diagnostic text or codes.

| Command | Successful effect | Deliberate failure |
| --- | --- | --- |
| `CreateCustomRole` | Creates an empty custom Role with fresh incarnation/revision. | Existing ID/name: `StateConflict`; reserved definition: `RuleViolation`. |
| `RenameCustomRole` | Renames the exact expected Role, or validates an unchanged name without writing. | Missing: `ResourceNotFound`; stale caller revision: `PreconditionFailed`; managed/reserved definition: `RuleViolation`; duplicate name/final CAS loss: `StateConflict`. |
| `RemoveCustomRole` | Removes the exact expected unreferenced custom Role. | Missing: `ResourceNotFound`; stale caller revision: `PreconditionFailed`; managed Role/known User reference: `RuleViolation`; final state/reference loss: `StateConflict`. |
| `GrantPermissionToCustomRole` | Adds an authoritative `ADMIN_SAFE` Permission, or validates existing membership. | Missing Role/Permission: `ResourceNotFound`; managed Role/protected Permission: `RuleViolation`; final definition/tier/state loss: `StateConflict`. |
| `RevokePermissionFromCustomRole` | Removes membership, or validates already-absent membership. | Missing Role/Permission: `ResourceNotFound`; managed Role: `RuleViolation`; final reference/state loss: `StateConflict`. |
| `AssignRoleToUser` | Adds the exact authoritative Role, or validates existing assignment. | Missing User/Role: `ResourceNotFound`; final User/reference CAS loss: `StateConflict`. |
| `RemoveRoleFromUser` | Removes the exact authoritative Role, or validates already-absent assignment. | Missing User/Role: `ResourceNotFound`; final User/reference CAS loss: `StateConflict`. |

Wrong requested User/Role/Permission identity, wrong name-index output, contradictory same-revision Role snapshots
and broken reserved Super Admin identity are internal contract faults, never not-found, ordinary rejection or no-op.
A name-index read showing the same Role at an advanced revision is instead a legitimate concurrent state conflict;
sequential reads do not promise one storage snapshot. Unknown
repository, clock, transaction and programmer faults retain their original throwable. This includes a repository
throwing a broad Domain exception: its class alone does not establish a known use-case outcome. Revision exhaustion
continues to fail closed before persistence; it is not a reason to reset an incarnation or infer a newer expectation.

Common's opt-in safe HTTP composition maps these deliberate categories to 404, 409, 412 and 422 respectively;
internal faults remain generic 500. Reporting owns diagnostics exactly once. Responses must not copy exception
messages, causes or traces. This is composition proof, not a new endpoint or MCP/OAuth error contract.

## No-ops and fences

Validate targets and referenced definitions before accepting unchanged intent. Rename checks the caller's exact
incarnation/revision even for the same name, then `RoleRepository::validateCurrent()` fences complete state through
transaction completion. Role Permission no-ops retain their Permission reference/tier check and also use that
complete-state non-writing Role fence. They cannot accept an earlier snapshot after another Role writer wins.
User membership retains reserved-identity validation, the final non-writing Role-reference check for no-ops and
complete expected User/replacement CAS for changes. No new caller revision is added to membership Commands.

A no-op writes nothing, changes no timestamp or revision and publishes no mutation fact. Real Role changes advance
one shared Role revision; real User membership changes advance only the User authorization-assignment revision.
Pending, disabled and deleted Users retain their existing Role-storage semantics; membership does not itself
activate a User or invalidate password recovery. The managed Super Admin Role remains assignable for bootstrap;
custom Roles and Agents cannot acquire protected Permissions merely because the actor is an administrator.

Every writer still shares transaction-duration identity/name, tier, registration and reference fences. Preserve
[Role revision/all-writer contracts](role-revisions.md), [Permission eligibility](permission-tiers.md) and
[registration admission](user-registration.md). A preliminary read is not final admission. Consumers qualify their
actual connection, locks, all writers, rollback and both winner orders; package models do not qualify SQL adapters.

## Transactions, facts and callers

One owning transaction persists the aggregate transition and any actually adopted atomic audit. These seven current
handlers do not gain an audit dependency or new audit feature here; optional audit remains separately owned.
Purpose-specific success facts follow confirmed commit only. Pre-commit failure rolls back, while lost commit
acknowledgement preserves uncertainty and a post-commit publication failure still throws with committed state intact.
Failure notification uses coarse constant text and cannot replace the primary throwable, even if both publishers
fail. No Agent issuance publication exception, outbox or replay guarantee is extended to this slice.

Known internal callback results are the exact success Event or Event/null, documented locally with `@var` while
Common UoW remains natively `mixed`. This needs neither a result DTO nor a generic UoW API or redundant result check.
Positive/negative [type fixtures](type-contracts.md) prove the seven void handler callbacks and reject wrong-purpose
identities, string revisions and consuming void as a result. They do not qualify generic bus payload/Event inference.
Real serialized routing, safe-error composition and persisted behavioral tests supply separate runtime evidence.

Update caller catches directly: Application consumers distinguish `ResourceNotFound`, `PreconditionFailed`,
`StateConflict` and `RuleViolation`, not the former broad `CustomRoleException`/`UserRoleAssignmentException` handler
outcomes. Direct Domain calls retain their Domain exceptions. On a failed displayed Role expectation, refresh and
reconfirm; never silently substitute a current revision. Successful Command payloads, Event payloads and OpenAPI
shapes are unchanged. Follow [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md): no old-contract aliases,
nullable modes or compatibility readers. These are breaking unreleased failure contracts, not a release decision.
