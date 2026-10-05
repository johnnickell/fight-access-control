---
id: TICKET-00017
epic: EPIC-00010
title: Manage and retire Features safely
status: done
---

# Manage and retire Features safely

## Problem and Outcome

Consumer operators need to inspect Feature settings, select preview audiences, and change availability without
silently overwriting another edit. Retiring a Feature must not leave known current code references dangling, and
removing a Permission must not invalidate Features that still reference it.

John approved this requirement area in the three-TICKET decomposition of
[EPIC-00010](../epics/00010-EPIC.md), then approved TASK-00065–00067 and the management details below. The complete
TASK decomposition is recorded; implementation and consumer UI work remain separately authorized.

## Use Cases

These are operation intents; exact PHP Command/Query/Event names and payloads belong to TASK design.

| Actor and trigger | Commands / operations | Queries / reads | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| An authorized consumer operator prepares a Feature ahead of deployment | Create a Feature with name and selected existing Permission | Existing Permission selection reads | Feature creation fact after commit | A new unique identity/name is persisted OFF under TICKET-00015's invariants. No implicit grant or Permission creation occurs. |
| An authorized operator opens or refreshes management | N/A | List Features, read one Feature and available Permission choices | N/A: reads do not mutate, commit, or dispatch domain events | Safe results expose identity/name, OFF/PREVIEW/ON status, bound Permission identity/display information, and expected-state information sufficient for subsequent edits. |
| An authorized operator selects a status | Change Feature status against expected state | Read current Feature when displaying or refreshing | Status-change fact after a real committed change | Persist the selected status without changing name or Permission. Reject stale edits without overwriting the newer record. Consumer workflow warns/confirms transitions out of ON. |
| An authorized operator selects a different testing Permission | Change Feature Permission against expected state | Read selected authoritative Permission and current Feature | Permission-binding-change fact after a real committed change | Persist the selected existing Permission ID without changing its definition or principal grants. Reject stale or invalid changes without partial mutation. |
| An authorized operator retires an unreferenced Feature | Guarded Feature deletion | Inspect current-code references and explicit registrations | Feature removal fact after commit | Reject if either references the name, or absence cannot be established. Otherwise remove the record without creating a permanent name reservation. |
| An authorized Permission administrator removes a Permission | Existing Permission-removal operation with Feature reference protection | Check all Feature references, irrespective of status | Existing Permission-removal success behavior only after a valid commit | Reject while any Feature references the Permission; reassign or remove referencing Features first. Existing Role/Agent reference guards continue to apply. |

No success event is published for a rejected change or an unchanged desired-state update. Queries never act as hidden
commands. Mutation handlers retain the project's Unit of Work, post-commit event, and failure-rethrow conventions;
EPIC-00009's credential-operation-only publication exception does not apply here.

## Management and Validation Requirements

- Manual creation reuses TICKET-00015's required existing Permission, unique immutable FeatureName, stable identity,
  and OFF initial state. A pre-created record need not already have a code reference. Later automatic provisioning
  must preserve it. No rename, alias, automatic code rewriting, or creation directly into PREVIEW/ON is introduced.
- The consumer UI supports the three statuses and selection of an existing Permission. Any currently defined
  Permission may be bound, including protected Permissions, under unchanged assignment and tier rules. A Feature
  reference is not a principal grant and adds no tier-promotion blocker.
- All status selections are available; moving from ON to PREVIEW or OFF requires warning and confirmation in the
  consumer workflow. Cancellation performs no package mutation. This is not a package-wide approval protocol or an
  independently public management API. No additional status-transition restrictions are silently added.
- John approved one shared Feature revision starting at 1, advancing by one only on real status/Permission changes.
  Updates and deletion require the expected revision, checked atomically at the final boundary, not merely preflight.
  Stale attempts reject; valid no-ops cause no writes, revision advance, or success event. All creation paths initialize
  revision 1 and repeat provisioning preserves it. Refresh and reconfirm if the intended transition still leaves ON.
  Do not add interactive locks, distributed coordination, or a single-administrator invariant.
- Permission changes affect the next explicit Feature evaluation under TICKET-00016's freshness rules. They do not
  alter the shared Permission definition, grant authority, recreate missing Permissions, or cancel admitted work.
- Unknown targets, malformed names/statuses, unresolved selected Permissions, duplicate creation, and stale updates
  fail without partial mutation or success events. Desired-state no-ops follow existing conventions without writes
  or revision advances; no-op status/Permission updates must not bypass required validation or stale-state checks.
- Read results disclose no principal secrets and do not answer business/action authorization. John approved retaining
  the stored Permission ID with an explicit missing-binding indicator when broken, not a substituted default or
  fabricated label. Reads expose the current revision and use existing pagination conventions. Operator recovery can
  select a valid existing Permission through the ordinary authorized update path.

## Reference Integrity and Retirement

- Every supported Permission-removal path must reject while any Feature references that Permission ID, including
  OFF and ON Features. Integrate with existing Role/Agent guards and managed-policy reconciliation where it removes
  definitions; do not guard only one convenient command while leaving another supported removal path open.
- Feature creation/rebinding and Permission removal must preserve the reference invariant under concurrent writes.
  Define the narrow repository/transaction obligations and prove that removal and a conflicting new reference cannot
  both succeed. Concrete database locking/constraints remain consumer choices, not a distributed deployment protocol.
- Feature deletion must check both current code references and explicit registered pointers, regardless of status.
  Removing only an Attribute or only a registry entry is insufficient if the other still references the Feature.
- Discovery failure, incomplete discovery, or inability to establish absence rejects deletion. A scanner error is not
  an empty reference set. Consumers identify actual current code, not an unrelated checkout or candidate inventory
  assumed to prove what is currently running.
- Retain released Features ON while references remain; remove code/registry references, deploy that removal, then retry
  deletion. Absence from a candidate release's inventory does not itself authorize deletion or remove records.
- This is a current-reference guarantee, not atomic coordination with arbitrary concurrent/future deployments.
  No cross-deployment locks, historical release ledger, permanent tombstone, or future-name reservation is required.
  Privileged direct database changes and undisclosed running code are not certified by the package guard.
- A later reintroduced name follows normal TICKET-00015 provisioning: new identity, OFF, current default Permission,
  no restoration of old settings. If deployment overlap/drift nevertheless produces an unknown runtime lookup,
  TICKET-00016's error applies; it must not become ON or trigger on-demand provisioning.

## Permissions and Consumer Integration

Consumers authorize every management/read/deletion entry point they expose, including direct command-bus invocation.
They may select a SUPER_ADMIN_ONLY management policy, but the package mandates no Permission name/tier for callers.
The Feature's testing Permission never confers management authority. Existing Permission-administration boundaries
and protected-tier assignment rules remain intact.

Consumers own UI, Permission selection widgets, warning/confirmation, refresh/reconfirmation, framework/backend
entry-point authorization, HTTP mappings, current-code scanning, persistence/schema, and deployment wiring. The package
owns reusable management and reference policies, not a second consumer implementation of those rules. Consumer
cancellation/confirmation and real database concurrency need their own integration evidence; package tests alone do
not prove them.

## Dependencies and Design Handoff

Depends on [TICKET-00015](00015-TICKET.md)'s values, creation invariants, repository/registry contracts, and reference
inventory semantics. [TICKET-00016](00016-TICKET.md) supplies evaluation for composed management/freshness scenarios;
management contracts can be specified independently of its completed implementation.

The approved implementation slices and true prerequisites are:

| TASK | Delivered outcome | Blocked by |
| --- | --- | --- |
| [TASK-00065](../tasks/00065-TASK.md) | All-path Permission reference protection, including managed-policy reconciliation and provisioning conflicts | [TASK-00062](../tasks/00062-TASK.md) |
| [TASK-00066](../tasks/00066-TASK.md) | Manual creation/read/list/status/binding management with shared revisions; actual rebinding conflicts and management-to-evaluation evidence | TASK-00065, [TASK-00064](../tasks/00064-TASK.md) |
| [TASK-00067](../tasks/00067-TASK.md) | Current-reference guarded deletion and complete retirement/reintroduction traceability | TASK-00066, [TASK-00063](../tasks/00063-TASK.md) |

Each TASK owns its production behavior tests, consumer-bindable scenarios, integration guidance, and full gate.
Revision/no-op/deletion checks, broken-binding read semantics, and pagination direction are now settled. The TASKs
own concrete message/error/read types and atomic repository mechanics, including public compatibility/schema impact.
Return genuine new product choices for approval; no new management surface or coordination protocol is implied.

TASK-00065 owns TICKET-00015's retained removal/provisioning integrity proof. TASK-00066 owns manual creation followed
by provisioning, actual rebinding/removal conflicts, and settings changes followed by evaluation. TASK-00067 owns
real guarded deletion followed by name reuse, preparation validation and OFF evaluation, and the final evidence map.
These downstream obligations introduce no reverse blocker on TASK-00061–00064 or dependency cycle. All required
cross-TICKET evidence remains unverified until executed; merely completing earlier TASKs cannot close it.

## Exclusions

No production UI, general management CLI, standalone public management API, persistence adapter/migration, scanner,
CMS migration, distributed deployment fencing, historical name reservation, in-flight cancellation, extra approval
system, or release/publication. Selection of a consumer management policy is separate from library eligibility.

## Acceptance Evidence

- [ ] Creation/list/read behavior supplies valid OFF records and sufficient safe state for consumer edits, rejects
      invalid/duplicate creation, and preserves pre-created choices during subsequent automatic provisioning.
- [ ] Status and Permission updates prove accepted values, required existing Permission IDs, immutable names, revision-1
      creation, +1 only on real changes, valid no-ops without writes/revision/events, and stale rejection including
      stale no-ops. Updates and deletion check expected revision atomically without partial changes or grant effects.
- [ ] Read/list results use existing pagination conventions, expose current revision, and retain broken Permission IDs
      with an explicit missing-binding indicator so authorized operators can repair them without default substitution.
- [ ] Consumer integration scenarios specify ON-to-PREVIEW/OFF warning/confirmation, cancellation, refresh and renewed
      confirmation after stale rejection. Package proof is explicitly distinguished from actual UI/authorization proof.
- [ ] Permission removal rejects Feature references in all three statuses across every supported removal path;
      concurrent create/rebind/removal contract scenarios preserve integrity and existing Role/Agent protections.
- [ ] Feature deletion rejects Attribute references, explicit registrations, and failed/incomplete discovery, permits
      retry after references are removed, and never treats candidate-code absence as current-code proof.
- [ ] Composed checks prove updated settings affect subsequent evaluations without cancelling admitted work, and later
      name reuse creates a new OFF record with the current default rather than resurrecting deleted settings.
- [ ] Failure and event tests prove rollback before commit, no success publication on rejection/no-op, and post-commit
      ordering without claiming reliable event delivery or rollback after a confirmed commit. Read paths never mutate.
- [ ] Integration guidance and consumer-bindable scenarios expose real scanner, persistence, authorization, and UI
      qualification obligations. Implementation passes `./bin/planning-check` and full `./bin/build`, including exact
      production statement coverage; planning checks are not implementation or consumer qualification.

## Decision Links and Progress

Implements [WF-018](../wayfinder/tickets/WF-018-feature-preview-permission-binding.md),
[WF-019](../wayfinder/tickets/WF-019-missing-feature-records.md),
[WF-020](../wayfinder/tickets/WF-020-feature-naming-and-management.md),
[WF-021](../wayfinder/tickets/WF-021-feature-evaluation-freshness.md), and
[WF-022](../wayfinder/tickets/WF-022-feature-package-integration-scope.md). Preserve
[ADR 0005](../adr/0005-agent-direct-permission-assignment-revision.md)'s existing Permission reference protection and
[ADR 0010](../adr/0010-permission-eligibility-and-caller-authorization.md)'s caller-authorization boundary.
John approved TASK-00065–00067 and the shared revision/read/consumer-workflow details. This completes TASK planning
for all three EPIC-00010 TICKETs: seven TASKs total, 00061–00067, with an acyclic dependency graph. The three TASKs
now progress independently against their recorded blockers, not an unrecorded product decision. TASK-00065 has
independently accepted package implementation and behavioral QA. John requested landing and published
[PR #104](https://github.com/johnnickell/fight-access-control/pull/104); approval and merge remain separate.
At the TASK-00065 checkpoint TASK-00066–00067 remained unfinished. TASK-00066 is subsequently accepted and done
for package management after independent technical review and behavioral QA (C1–C9 pass, no findings). At that
checkpoint TASK-00067 still owned outstanding retirement/lifecycle evidence; its subsequent acceptance follows.
Consumer qualification, adoption and package release remain separate.

John subsequently selected the main checkout for TASK-00067 from clean `develop` `09abe1f`, on
`feature/task-00067-feature-retirement`. Guarded retirement, full-state final deletion/reference release and the
real deletion/reintroduction lifecycle composition were implemented at that builder checkpoint; the
[complete evidence map](../../docs/feature-evidence.md) retains previous acceptance subjects and consumer gaps.
Independent technical review subsequently **accepts** clean TASK-00067 candidate
`8e33f0847d41aff562e6258b1a360d70d152dfe1` against `09abe1f` (C1–C10 pass, no findings). Independent post-review
QA **PASS** covers seven scenarios / 260 executable checks plus a ten-case instruction walkthrough; focused
verification passes 174 tests / 977 assertions. The accepted full gate passes 1849 tests / 35482 assertions,
exact 6627/6627 statements. The TASK owns canonical reports and retained evidence, including historical
TASK-00061–00066 subjects and fresh actual retirement/reintroduction/reconciliation proof.

TASK-00065–00067 are now terminal; automatic parent completion marks this TICKET and EPIC-00010 done in the same
closeout. This status follows accepted children, not an inference from prose or a separate parent gate. John
subsequently authorized landing; publication is pending at this checkpoint. Actual consumer scanner/database,
UI/authorization/runtime qualification, consumer adoption, release and deployment remain separate.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 65 | [TASK-00065](../tasks/00065-TASK.md) | Protect Permissions referenced by Features | done |
| 66 | [TASK-00066](../tasks/00066-TASK.md) | Create, inspect, and update Feature settings safely | done |
| 67 | [TASK-00067](../tasks/00067-TASK.md) | Retire Features through current-reference guards | done |
