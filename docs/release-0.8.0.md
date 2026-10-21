# v0.8.0 release and integration overview

**Prepared, not yet published.** John selected v0.8.0 on 2026-10-21. The release branch starts from `develop`
`6ea05dc3270f1bd314fce9878940a07be528791e`, including merged
[PR #133](https://github.com/johnnickell/fight-access-control/pull/133). v0.7.0 remains the latest verified published
release. A dated Changelog, versioned README or passing local gate is not evidence of signing or publication.

## Scope and compatibility

This breaking pre-v1 minor includes the independently accepted and integrated operation-outcome
([TASK-00077](../planning/tasks/00077-TASK.md)), recovery-lifecycle
([TASK-00078](../planning/tasks/00078-TASK.md)), managed-policy
([TASK-00079](../planning/tasks/00079-TASK.md)) and Role caller-revision
([TASK-00080](../planning/tasks/00080-TASK.md)) work. It also includes optional contributor-workflow alignment
([TASK-00081](../planning/tasks/00081-TASK.md)) and the branded package entrypoint/release consistency preflight
([TASK-00082](../planning/tasks/00082-TASK.md)). No new production implementation is part of release preparation.

- PHP **8.5+** and Fight Common **^1.3** requirements are unchanged.
- This is **not a v0.7.x patch**: consumers must implement required caller payloads, Views, constructors, repository
  methods and persisted state. Regenerate consumer-owned OpenAPI documents against the current components.
- Only the current pre-v1 API and persisted contract are supported under
  [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). No historical readers, compatibility defaults,
  migration/backfill paths or destructive data reset are supplied or authorized.
- Role revisions are state expectations, not credentials or authorization. Consumer exposure, permissions,
  administrator policy and destructive confirmation remain consumer-owned.
- This release does not select v1.0.0 or establish a new maintenance/support window.

## Required consumer composition

| Boundary | Current contract and required action |
| --- | --- |
| Role detail/list and caller intent | `RoleView` requires authoritative `RoleRevision` and exposes `revision`. `RenameCustomRole(actorId, roleId, name, expectedRevision)` and `RemoveCustomRole(actorId, roleId, expectedRevision)` require the exact displayed token through canonical `expected_revision`. Missing/malformed input has no fallback. On typed `RoleConflictException`, refresh and reconfirm; never silently substitute a newer token. |
| Role persistence and all writers | Persist/hydrate the complete random-incarnation/sequence token. `Role::reconstitute()` requires revision after timestamps. Real name/membership changes advance exactly once; no-ops do not. Implement non-writing `RoleRepository::validateCurrent(expectedRole)` and complete-state replace/remove comparisons under transaction-duration identity/name/reference fences. Recreated IDs use new incarnations. Registration's complete Role comparisons include revision. |
| Reset-generation persistence | `PasswordResetGrant::issue()` requires immutable `RecoveryAuthority` from the User namespace as its final argument; protected hydration requires it immediately after delivery. Persist the issuing authentication version and canonical-email revision, including terminal history, and compare both in complete-state CAS. Never infer them from the latest User during hydration. They are not new caller/OpenAPI fields. |
| Recovery validation and lifecycle | Implement `UserRepository::validateRecoveryAuthority(expectedUser)` against complete current User state on the shared connection. Every User/reset-grant writer shares the transaction-duration fence with consistent User-before-grant lock order. Couple authority-changing User mutation, reset cancellation/material destruction and required audit atomically; enable/restore does not reissue recovery. |
| Required constructor wiring | Append the shared `UserRepository` after the dispatcher in `DeliverPasswordResetHandler`; append the shared `PasswordResetGrantRepository` after the dispatcher in `DisableUserHandler` and `DeleteUserHandler`. Preserve the existing shared authentication/request-reset composition. |
| Managed-policy composition | Preview/apply use the same planner with Permission, Role, User and Agent repositories and complete authoritative reference reads. Preview is read-only and reserves nothing; apply replans and retains final expected-state/reference fences. All Role, Agent and Feature reference writers share those fences through completion. |
| Notifications and callers | Accept coarse constant failure notifications and safe command/context evidence without requiring dependency diagnostics. Failure notification cannot replace the primary throwable. Separately sanitize rethrown faults in responses/logs and protect identity-bearing events; notification policy is not throwable sanitation. |

The [Role revision guide](role-revisions.md) owns token grammar, bounded sequence/exhaustion, hydration, all-writer
participation, stale intent and consumer-bindable scenarios. The [consolidated operation-outcome guide](operation-outcomes.md)
owns recovery constructor signatures, persistence hooks, worker admission, policy reference fencing and outcome semantics.
Existing [registration](user-registration.md) and [delivery](credential-delivery.md) obligations still apply.

## Outcomes and recovery boundaries

- Valid same-name custom Role rename validates/fences current state without writing, changing timestamps/revision or
  publishing success. Membership command inputs are unchanged; real membership changes still participate as writers.
- Confirmed pre-commit failures roll back coupled mutation/audit. Success events follow confirmed commit only;
  a later publisher/subscriber fault still throws without undoing committed state.
- Recoverable Agent provision and rotation alone retain their typed committed-result publication-warning exception.
  Role, recovery, managed-policy, revocation and authentication operations do not inherit it.
- Lost response or commit acknowledgement does not prove rollback. Resolve current persisted state under current
  authorization, rather than infer outcomes from notifications or blindly replay stale commands.
- Recovery provider invocation remains outside transactions and at-least-once. An admitted invocation may arrive late
  externally; its outcome cannot restore cancelled authority or acknowledge a successor. No withdrawal of sent mail,
  distributed provider transaction, exactly-once delivery, guaranteed event replay or outbox is supplied.

These are current composition requirements, not an affected-release assessment or a disclosure of private diagnostics.

## Evidence and release boundary

Canonical TASK records and maintainer-held reports retain exact implementation subjects, independent technical
acceptance and behavioral QA for the included slices. The release coordinator has checked those dispositions and
integration ancestry; that is not a new independent assessment of the implementation or the release documentation.
Controlled package tests and consumer-bindable conformance suites are not actual consumer adapter qualification.

The preparation `./bin/build` passes **2399 tests / 45422 assertions**, exact **7452/7452 statements**, without
warnings/skips. Planning validates **118 records / 0 active**; `./bin/release check-docs 0.8.0`, documentation links
and whitespace checks pass. Local README light/dark desktop and narrow previews pass; they do not prove hosted
rendering. No dependency/lockfile drift occurred; rebuilt image metadata retains identical runnable Config/RootFS.
This verification-prose follow-up retains that full gate through unchanged executable inputs and fresh targeted
checks, not a second build. Saved receipts identify tested content, direct exit and runtime/dependency state.

Independent release-content acceptance and authorized integration remain required. After integration,
run fresh `./bin/release certify 0.8.0` on the **exact clean main release merge commit**, not a tree-equivalent earlier
candidate. Certification separately records version consistency, planning, full package quality and OpenAPI composition.

At this preparation checkpoint there is no final-main certification, signed v0.8.0 tag, remote tag, GitHub release
or Packagist projection. Each must be verified independently against the certified commit; registry availability is
not installed-consumer proof. Follow the [release runbook](releasing.md), then reconcile publication metadata through
normal delivery, complete authorized main-to-develop merge-back and clean only proven-owned resources. Never retag
merely to change documentation's preparation status.

## Unexecuted consumer qualification

Consumers must qualify their actual row mapping and all required persisted values, database isolation/shared locks,
all-writer participation and both concurrent winner orders (including Role registration/reference races), rollback,
uncertain commits, response loss and physical restart. Bind the provided Role/recovery conformance hooks to real
persistence without weakening assertions. Qualify displayed-token propagation and reconfirmation, all dispatch/query
permissions, current worker/provider composition, idempotency and delayed outcomes, keys/ciphers, and response/log
sanitation. Package in-memory models do not establish these facts.

No consumer schema, adapter, transport/UI, source/lock update, installation, enablement or deployment is performed or
authorized by this release preparation. Restricted evidence remains private under [SECURITY.md](../SECURITY.md).
