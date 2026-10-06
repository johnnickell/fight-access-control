# Roadmap

## In progress

| Epic | Target | Current outcome |
| --- | --- | --- |
| [EPIC-00009](epics/00009-EPIC.md) | `v0.5.0` | Package current-contract cleanup, cohort/canonical/restoration safety and final guidance are independently accepted; TASK-00059 QA passes. Real consumer qualification and separately authorized release/adoption/deployment remain open. |

## Human delivery expiry readiness prerequisite

`v0.5.0` is not ready to tag until the newly requested
[human credential-delivery expiry capability](wayfinder/human-credential-delivery-expiry-map.md) has approved upstream
work records, complete implementation/local verification and independent acceptance with applicable behavioral QA.
[WF-025](wayfinder/tickets/WF-025-human-delivery-expiry-contract.md) is resolved: invitation/reset delivery expiry plus
full email-change authority/reservation expiry, including already-terminal delivery and changed User account states.
John confirmed [EPIC-00012 — Package-Owned Human Credential Expiry Cleanup for v0.5.0](epics/00012-EPIC.md); its only
Wayfinder destination is written and the map is Closed. John approved its single cohesive requirement area, now
[TICKET-00020](tickets/00020-TICKET.md), retaining the entire WF-025 matrix, then approved its sole implementation slice,
[TASK-00072](tasks/00072-TASK.md). Full EPIC/TICKET/TASK decomposition is accepted and complete; Board execution selection,
implementation authority and placement were separately resolved for TASK-00072: John selected main and requested
inclusion of pending planning changes in its commit. At the pre-publication checkpoint, the implementation passes
206 focused tests / 2957 assertions and the complete gate (1993 tests / 37644 assertions), exact 6841/6841 product
statements and 971/971 changed-production statements. Subsequent revised candidate
`9bdf3822eb5f97505cca8bc76f2f1a14dc5d28d0` has independent technical acceptance (F1–F3 resolved, no findings) and
post-review nonvisual QA PASS: six scenario groups, 86 driver cases / 795 checks and 357 conformance tests / 5780 assertions.
Its accepted complete gate passes 2019 tests / 39818 assertions, exact 6841/6841 owned statements, no final warnings/skips.
TASK-00072 is done for accepted package implementation/local verification; automatic parent completion closes TICKET-00020
and EPIC-00012 in the same closeout. This package readiness prerequisite is satisfied, not release certification or actual
consumer qualification. John authorized landing; final PR publication verification is pending at this tracked checkpoint.
The TASK's ignored landing receipt owns fresh checks, administrative-only provenance and actual PR/remote identity.
Before this implementation, due discovery could not clean recoverable invitation/reset
work after downtime through grant expiry; the unchanged due path now has separate expired discovery/direct cleanup. Email reservations must not remain stranded because delivery finished or the account
became inactive; no consumer product/provider wiring is enabled. This follow-up does not reopen completed EPIC-00009
or its original delivery predecessors. Agent OS `TASK-00024` is the external blocked consumer work, not an AccessControl
dependency edge. Package proof must be distinguished from actual PostgreSQL consumer qualification/adoption. Tagging,
signing, pushing and publication remain separately authorized; the Board still owns execution priority, unchanged by
this approved decomposition.

## Current package acceptance and next TASK

John's 2026-09-30 package-wide decision in [ADR 0011](adr/0011-pre-v1-current-contract-only.md) removes all obligations
to support previous APIs/data iterations while pre-v1. [TASK-00068](tasks/00068-TASK.md) has accepted removal of
legacy-Agent mode/API stubs and credential-delivery compatibility defaults. TASK-00058's current-contract restoration
and TASK-00059's final integration guidance/evidence are independently accepted; TASK-00059 behavioral QA also passes.
John authorized TASK-00059 landing, with publication pending at this tracked checkpoint. Real consumer evidence remains
open; no migration, release or deployment is claimed. The [Board](tasks/BOARD.md) owns next executable work.

### Original decomposition context

[EPIC-00009](epics/00009-EPIC.md) prepares recoverable Agent provisioning and rotation from the accepted-for-planning
Agent OS proposal. The breaking replacement, ADR 0004/WF-002 amendment, transactional authorization/protected-sink
participation, scoped post-commit result behavior and amended bounded policy are ratified. Finite defaults and optional
validated overrides bound credential operations; capacity handling preserves existing-operation recovery. Committed
issuance does not establish delivery, enrollment activation or launch authority. John confirmed the shared destination
and boundaries on 2026-09-27, then approved the decomposition into
[TICKET-00012](tickets/00012-TICKET.md) (issuance/resolution),
[TICKET-00013](tickets/00013-TICKET.md) (protected delivery/recovery) and
[TICKET-00014](tickets/00014-TICKET.md) (migration/compatibility and evidence traceability).

At the approved decomposition checkpoint, TICKET-00012's TASK split was recorded:
[TASK-00046](tasks/00046-TASK.md) was the first ready provisioning slice;
[TASK-00047](tasks/00047-TASK.md) reads status after it. [TASK-00048](tasks/00048-TASK.md) (rotation) and
[TASK-00049](tasks/00049-TASK.md) (conformance) now have their approved TICKET-00013 dependencies recorded and remain
waiting, not needs-info. TICKET-00013's [TASK-00050](tasks/00050-TASK.md)–[TASK-00054](tasks/00054-TASK.md) cover
retirement fences, protected delivery, restart recovery, material maintenance and delivery/lifecycle conformance.
They begin after TASK-00046 through an acyclic graph; neither conformance TASK blocks its implementation inputs.
TICKET-00014's [TASK-00055](tasks/00055-TASK.md)–[TASK-00059](tasks/00059-TASK.md) now cover legacy compatibility,
cohort enforcement, canonical upgrades, restoration safety and final migration/evidence guidance. All three TICKETs
have approved TASK splits; at that checkpoint TASK-00046 was first ready and TASK-00047–00059 waited on dependencies.
Concrete limits and compatibility details remain design/proof obligations before implementation acceptance. Planning readiness is
not execution authorization; intermediate PRs are not a supported partial release or consumer qualification.
John selected `v0.5.0` as the target release. Implementation, release/publication, consumer upgrade and Agent OS
TASK-00138 closure remain separately authorized operations.

Current package checkpoint: TASK-00056's cohort safety and revised TASK-00057's single canonical contract are
independently accepted, as are TASK-00068 removal (revised M1), TASK-00058 restoration (M4) and TASK-00059 guidance
(M5). TASK-00055's legacy preservation is superseded, not a continuing obligation. Real consumer qualification remains
outstanding; package acceptance does not close the parent consumer obligations. The [Board](tasks/BOARD.md) owns
current executable ordering.

## MCP planning readiness

Installed Fight Common v1.3.0 now satisfies the external Tool API gate; the exact revision/signatures and source
inspection limits are recorded in [TASK-00035](tasks/00035-TASK.md#verified-fight-common-v130-contracts).
EPIC-00005/TICKET-00006 and EPIC-00011/TICKET-00019 are `ready-for-agent`, not complete. TASK-00035 is executable;
TASK-00071 is ready but waits on unfinished TASK-00070 and TASK-00035 (TASK-00069 is done). Existing order values
are unchanged, so the generated [Board](tasks/BOARD.md) now places TASK-00035 first, ahead of TASK-00070.
The related Wayfinder map remains Closed; no planning phase is reopened. This refresh neither implements nor
qualifies MCP integration, changes dependencies, or authorizes a release/consumer adoption.

## Feature package acceptance

[EPIC-00010 — Permission-Based Feature Flags](epics/00010-EPIC.md) has an approved three-TICKET decomposition from
its closed [Wayfinder map](wayfinder/permission-based-feature-flags-map.md):
[TICKET-00015](tickets/00015-TICKET.md) establishes registration/provisioning,
[TICKET-00016](tickets/00016-TICKET.md) defines fresh Permission-based availability for Users and Agents, and
[TICKET-00017](tickets/00017-TICKET.md) defines management and guarded retirement. The latter two build on the first's
shared contracts; each includes its evidence and consumer integration obligations. Consumers separately own
persistence, scanning, enforcement, UI, and deployment; actual CMS adoption is not included.

TICKET-00015's approved chain is [TASK-00061](tasks/00061-TASK.md) (declarations/inventory) →
[TASK-00062](tasks/00062-TASK.md) (atomic provisioning) → [TASK-00063](tasks/00063-TASK.md) (preparation validation and
reusable contract scenarios). John approved method-only/nonrepeatable Attributes and one transaction per provisioning
pass, with fresh-state retries and no promise of reliable event delivery. TICKET-00016's approved
[TASK-00064](tasks/00064-TASK.md) depends only on TASK-00062 and delivers the complete fresh, ID-safe evaluator with
existing User/Agent snapshots or null, boolean availability, and distinguishable errors. No shared authority-interface
change is needed. TICKET-00017 now has approved [TASK-00065](tasks/00065-TASK.md) (Permission reference protection,
after 00062), [TASK-00066](tasks/00066-TASK.md) (management, after 00065/00064), and
[TASK-00067](tasks/00067-TASK.md) (guarded retirement and final lifecycle evidence, after 00066/00063).
John approved shared revision-1 creation, increments only on real status/Permission changes, expected revisions for
updates/deletion, and explicit broken-binding reads using existing pagination. Consumer confirmation and authorization
remain separate. At the decomposition checkpoint all three TICKETs and seven TASKs were planned; that planning
handoff alone authorized no implementation, release target or execution worktree.

All seven TASKs subsequently obtained independent technical acceptance and behavioral QA. TASK-00067's clean
accepted candidate `8e33f0847d41aff562e6258b1a360d70d152dfe1` supplies actual guarded retirement/reintroduction
and final traceability; QA passes seven scenarios / 260 executable checks plus an instruction walkthrough.
Its accepted full gate passes 1849 tests / 35482 assertions, exact 6627/6627 statements. Automatic parent
completion closes TICKET-00017 and EPIC-00010 in the same TASK closeout; TICKET-00015/00016 are already done.
Package acceptance is complete, not actual consumer scanner/database/UI/security/runtime qualification. John
authorized TASK-00067 landing; publication remains pending at this tracked checkpoint. No Feature release target,
adoption, release or deployment is claimed; the Board still owns separately authorized next executable work.

## Route to 1.0.0

1. Publish completed framework-neutral capabilities as reviewed pre-`1.0.0` package releases, beginning with
   `v0.1.0`, without making full starter implementation a package-release prerequisite.
2. Implement the full Symfony, Laravel, Yii,
   CodeIgniter, and Slim starter skeletons against tagged package versions.
3. Feed shared compatibility findings into reviewed subsequent `0.x` releases while framework-specific fixes
   remain in their owning starter repositories.
4. Run a separate stability review before authorizing a `1.0.0` release.
5. Keep implementation, commit, push, pull request, merge, release, and publication as separate approvals.

## Completed

| Epic | Target | Outcome |
| --- | --- | --- |
| [EPIC-00012](epics/00012-EPIC.md) | `v0.5.0` | Human expiry discovery/direct cleanup independently accepted with post-review behavioral QA; TASK-00072 and TICKET-00020 complete. Actual PostgreSQL/consumer qualification, adoption, merge, release and deployment remain separate. |
| [EPIC-00010](epics/00010-EPIC.md) | unassigned | All seven Feature package TASKs independently accepted with behavioral QA; guarded retirement/reintroduction and cross-TICKET evidence complete. Actual consumer qualification, version selection, adoption, release and deployment remain separate. |
| [EPIC-00006](epics/00006-EPIC.md) | `v0.3.0` | Published the recoverable credential-delivery contract and migration after exact-commit certification and signing. Consumer upgrades remain separately qualified. |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | Replaced deprecated Fight Common transaction dependencies with the supported `TransactionalUnitOfWork` contract while preserving established behavior. |
| [EPIC-00004](epics/00004-EPIC.md) | `v0.2.0` | Delivered the local consumer-composable OpenAPI schema review candidate; tag, release, and publication remain separate effects. |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | Delivered the final unified User/Agent `SecurityContext` and retry-safe Agent Permission, User Role, and custom-Role Permission changes. |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

| Version | Date | Outcome |
| --- | --- | --- |
| `v0.3.0` | 2026-09-25 | Published recoverable, provider-neutral credential delivery for invitations, password resets, and email changes, with pre-1.0 breaking migration guidance. |
| `v0.2.0` | 2026-09-13 | Published consumer-composable OpenAPI schema components. |
| `v0.1.0` | 2026-09-10 | First public package milestone: framework-neutral User and Agent identity, authentication, session, Role, Permission, managed-policy, and current-authority behavior with exact coverage. |

## Record Status Projection

<!-- generated:epic-status:start -->
| EPIC | Target | Status | Tickets | Tasks |
| --- | --- | --- | --- | --- |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | done | 1 | 18 |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | done | 1 | 7 |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | done | 2 | 6 |
| [EPIC-00004](epics/00004-EPIC.md) | v0.2.0 | done | 1 | 1 |
| [EPIC-00005](epics/00005-EPIC.md) | 0.x | done | 1 | 1 |
| [EPIC-00006](epics/00006-EPIC.md) | v0.3.0 | done | 1 | 3 |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | done | 1 | 1 |
| [EPIC-00008](epics/00008-EPIC.md) | v0.4.0 | done | 3 | 5 |
| [EPIC-00009](epics/00009-EPIC.md) | v0.5.0 | done | 3 | 14 |
| [EPIC-00010](epics/00010-EPIC.md) | unassigned | done | 3 | 7 |
| [EPIC-00011](epics/00011-EPIC.md) | unassigned | ready-for-agent | 2 | 3 |
| [EPIC-00012](epics/00012-EPIC.md) | v0.5.0 | done | 1 | 1 |
<!-- generated:epic-status:end -->
