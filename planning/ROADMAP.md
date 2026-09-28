# Roadmap

## In progress

| Epic | Target | Current outcome |
| --- | --- | --- |
No epics are currently in progress.

## Approved planning and next TASK

[EPIC-00009](epics/00009-EPIC.md) prepares recoverable Agent provisioning and rotation from the accepted-for-planning
Agent OS proposal. The breaking replacement, ADR 0004/WF-002 amendment, transactional authorization/protected-sink
participation, scoped post-commit result behavior and amended bounded policy are ratified. Finite defaults and optional
validated overrides bound credential operations; capacity handling preserves existing-operation recovery. Committed
issuance does not establish delivery, enrollment activation or launch authority. John confirmed the shared destination
and boundaries on 2026-09-27, then approved the decomposition into
[TICKET-00012](tickets/00012-TICKET.md) (issuance/resolution),
[TICKET-00013](tickets/00013-TICKET.md) (protected delivery/recovery) and
[TICKET-00014](tickets/00014-TICKET.md) (migration/compatibility and evidence traceability).

TICKET-00012's approved TASK split is now recorded: [TASK-00046](tasks/00046-TASK.md) is the first ready provisioning
slice; [TASK-00047](tasks/00047-TASK.md) reads status after it. [TASK-00048](tasks/00048-TASK.md) (rotation) and
[TASK-00049](tasks/00049-TASK.md) (conformance) now have their approved TICKET-00013 dependencies recorded and remain
waiting, not needs-info. TICKET-00013's [TASK-00050](tasks/00050-TASK.md)–[TASK-00054](tasks/00054-TASK.md) cover
retirement fences, protected delivery, restart recovery, material maintenance and delivery/lifecycle conformance.
They begin after TASK-00046 through an acyclic graph; neither conformance TASK blocks its implementation inputs.
TICKET-00014's [TASK-00055](tasks/00055-TASK.md)–[TASK-00059](tasks/00059-TASK.md) now cover legacy compatibility,
cohort enforcement, canonical upgrades, restoration safety and final migration/evidence guidance. All three TICKETs
have approved TASK splits; TASK-00046 remains first ready and TASK-00047–00059 wait on dependencies. Concrete limits
and compatibility details remain design/proof obligations before implementation acceptance. Planning readiness is
not execution authorization; intermediate PRs are not a supported partial release or consumer qualification.
John selected `v0.5.0` as the target release. Implementation, release/publication, consumer upgrade and Agent OS
TASK-00138 closure remain separately authorized operations.

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
| [EPIC-00005](epics/00005-EPIC.md) | 0.x | needs-info | 1 | 1 |
| [EPIC-00006](epics/00006-EPIC.md) | v0.3.0 | done | 1 | 3 |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | done | 1 | 1 |
| [EPIC-00008](epics/00008-EPIC.md) | v0.4.0 | done | 3 | 5 |
| [EPIC-00009](epics/00009-EPIC.md) | v0.5.0 | ready-for-agent | 3 | 14 |
<!-- generated:epic-status:end -->
