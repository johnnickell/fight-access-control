# Roadmap

## Future MCP permission integration — current acceptance

TASK-00085 and TASK-00086 are independently accepted with required nonvisual QA; TICKET-00026 is done.
TASK-00086 publication and final delivery were pending at closeout; its ignored Land receipt owns the current result.
The remaining EPIC scope and combined release remain unfinished. The planning history below retains its original checkpoint.

### Planning history

[EPIC-00014 — Permission-Aware MCP Integration and Framework Adapters](epics/00014-EPIC.md) now owns the single
approved destination from the [closed Wayfinder map](wayfinder/mcp-capability-permission-integration-map.md).
All four decisions and the map's EPIC phase are complete. The accepted combined release includes Tool/execution,
Resource/Skill/Prompt, Subscriptions and durable Task permission integration, Common-compatible safe errors and all
five framework families (Slim, Yii 3, Symfony, Laravel, CodeIgniter 4), with optional capability-first `src/Adapter`
integrations and consumer-owned policy/scope. The release number remains unassigned.

John approved the full eight-TICKET split, now written as [TICKET-00025](tickets/00025-TICKET.md) through
[TICKET-00032](tickets/00032-TICKET.md). All map EPIC/TICKET phases are complete. John subsequently selected
TICKET-00025 for To Tasks and approved its single-TASK plan, now written as [TASK-00084](tasks/00084-TASK.md).
It is `needs-info` for actual qualified/tagged Common execution contracts and minimum-version evidence, with no
unfinished local TASK blockers. John subsequently approved TICKET-00026's complete two-TASK plan, written as
[TASK-00085](tasks/00085-TASK.md) for Resources and [TASK-00086](tasks/00086-TASK.md) for whole Skills/member reads.
TASK-00085 has completed its accepted Resource implementation and required package verification; [PR #139](https://github.com/johnnickell/fight-access-control/pull/139)
is open against `develop`. TASK-00086 depends only on TASK-00085, not TASK-00084. Qualifying its actual supported
Common minimum/behavior remains an implementation obligation, not a missing execution-Tool API hold.
John then approved TICKET-00027's complete single-TASK plan, written as [TASK-00087](tasks/00087-TASK.md), needs-info
for qualified/tagged Common Prompt contracts and supported minimum evidence, with no unfinished local TASK blockers.
John then approved TICKET-00028's single-TASK plan, written as [TASK-00088](tasks/00088-TASK.md), ready-for-agent
with no local TASK blocker or new Common runtime API prerequisite. It owns trusted binding/current checks and
synchronized protected admission, with actual consumer lifetime/scope/fence qualification separate.
John then approved TICKET-00029's full plan, written as [TASK-00089](tasks/00089-TASK.md) for scoped timeline reads
and [TASK-00090](tasks/00090-TASK.md) for independent protected metadata/body reads. Both consume TASK-00084 and are
needs-info for qualified/tagged Common scoped-read contracts/minimum evidence; neither depends on the other or on
TASK-00088 for ordinary requests. Consumer scope/persistence/race qualification remains separate.
John then approved TICKET-00030's six complete journeys, written as [TASK-00091](tasks/00091-TASK.md) recording/removal,
[TASK-00092](tasks/00092-TASK.md) retained inspection/recovery, [TASK-00093](tasks/00093-TASK.md) live Tool/Resource/Skill
disclosure, [TASK-00094](tasks/00094-TASK.md) Prompt signals, [TASK-00095](tasks/00095-TASK.md) subscriber inspection and
[TASK-00096](tasks/00096-TASK.md) optional publication. All six are needs-info for qualified/tagged Common contracts/
minimum evidence, separately from approved local supplier edges. Publication is independent of live/Prompt/inspection,
Prompt absence does not block ordinary live families, and polling needs no live runtime.
John then approved TICKET-00031's six complete features, written as [TASK-00097](tasks/00097-TASK.md) creation/producer/
protected reads, [TASK-00098](tasks/00098-TASK.md) removal, [TASK-00099](tasks/00099-TASK.md) input/continuation,
[TASK-00100](tasks/00100-TASK.md) cancellation/intent, [TASK-00101](tasks/00101-TASK.md) complete MCP start/reconnect and
[TASK-00102](tasks/00102-TASK.md) optional full-state notifications. All six retain needs-info qualified/tagged Common
contract/minimum holds plus approved supplier edges. Ordinary polling/input/cancel need no live runtime, removal does
not block complete activation, and full-state live has no extra Prompt/inspection/Mercure blocker.
John then approved TICKET-00032's full four-feature plan, written as [TASK-00103](tasks/00103-TASK.md) shared PSR-15
with separate Slim/Yii 3 qualification, [TASK-00104](tasks/00104-TASK.md) Symfony,
[TASK-00105](tasks/00105-TASK.md) Laravel and [TASK-00106](tasks/00106-TASK.md) CodeIgniter 4. All are ready-for-agent:
TASK-00103 has no local blocker; B–D consume only its inward shared contract/first Adapter wiring, not Slim/Yii runtime,
and share equal order 104 without relative native framework priority. No inherited MCP/TASK-00088 prerequisite;
actual sufficient tagged minima/native qualification remain acceptance work. All map decisions/EPIC/TICKET/full TASK
plans are accepted/written (TASK-00084–00106, 23 features). Next: `/skill:next` for Board-based selection, not automatic
execution or release. Only TASK-00085's accepted Resource slice is implemented; the remaining EPIC scope is not.
Required Common implementations, independent acceptance/qualification, tagged APIs and actual minimum-version/framework
evidence gate affected adoption/release, not this planning handoff. The
[research](wayfinder/research/fight-common-mcp-permission-integration.md) preserves initial source snapshots;
the EPIC records current local maturity observations without consumer qualification.
John subsequently authorized commit, push and a planning-only PR to develop. No grants, dependency changes,
implementation, merge, release or consumer adoption are authorized by that request. The published v0.8.0 handoff
below is unchanged.

## Current release handoff — v0.8.0 published

[v0.8.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.8.0) is published on 2026-10-21 from
certified main merge `319738255e682d587642b67856039f882b9588d6` through
[PR #134](https://github.com/johnnickell/fight-access-control/pull/134). Independent release-content acceptance,
documentation QA, fresh exact-main certification, human-signed local/remote tag, immutable latest/non-prerelease
GitHub publication and matching Packagist source/dist projection are verified. The
[release overview](../docs/release-0.8.0.md) owns identities, breaking Role/recovery composition and unexecuted consumer
qualification. PHP 8.5+ and Common ^1.3 remain unchanged.

Main-to-develop merge-back completed through [PR #135](https://github.com/johnnickell/fight-access-control/pull/135)
on 2026-10-21 at `d955d2f88cf58a5696814adeebb609a64fff8ee9`. Release-owned cleanup is satisfied: both
`release/0.8.0` and `release/0.8.0-merge-back` are absent locally and on origin, with no isolated release worktree.
The release ledger retains the verified closeout and evidence; no release-owned action remains. Preserve the signed
release commit and evidence.
At v0.8.0 release closeout no implementation TASK remained unfinished; the new future-integration TASK-00084–00106 records are
tracked above. v1.0.0 stability, consumer adoption and deployment remain separate decisions.

### Historical v0.8.0 preparation checkpoint

John selected v0.8.0 and the main checkout on 2026-10-21. Preparation used `release/0.8.0` from refreshed `develop`
`6ea05dc`, including merged [PR #133](https://github.com/johnnickell/fight-access-control/pull/133). At that checkpoint,
v0.7.0 was the latest published release; independent release-content acceptance, main integration, exact-main
certification, human signing and publication were still required. The current handoff above supersedes those pending
steps without erasing their historical checkpoint meaning.

## Correction batch integrated

The [correction map](wayfinder/access-control-correction-batch-map.md)'s decisions and decomposition are complete.
[EPIC-00001](epics/00001-EPIC.md) retains existing requirement ownership through
[TICKET-00001](tickets/00001-TICKET.md#approved-correction-batch-amendment), plus new
[TICKET-00024](tickets/00024-TICKET.md) for Role caller revisions.

The accepted TASKs are [TASK-00077](tasks/00077-TASK.md) for operation outcomes,
[TASK-00078](tasks/00078-TASK.md) for recovery lifecycle, [TASK-00079](tasks/00079-TASK.md) for policy consistency
and [TASK-00080](tasks/00080-TASK.md) for Role caller revisions. TASK-00078/00079 depend on TASK-00077;
TASK-00080 depends on TASK-00077/00079. TASK-00078 precedes TASK-00079 by priority only.

The map is Closed for planning. TASK-00077–00079 are complete and integrated. TASK-00080 has independent technical
acceptance and nonvisual behavioral QA on its exact implementation candidate; its sole-child TICKET-00024 is done through
automatic parent completion while EPIC-00001 retains its historical terminal status. Its [current Role composition](../docs/role-revisions.md)
and saved local gates are package evidence, not consumer qualification. Public summaries do not replace approved maintainer
briefs or authorize restricted disclosure. [TASK-00080 PR #132](https://github.com/johnnickell/fight-access-control/pull/132)
merged into `develop` on 2026-10-21 at `d1ed655`; the correction batch has no remaining decomposition,
implementation or PR-merge handoff. Consumer qualification, version selection, release and deployment remain separate.

[TASK-00082](tasks/00082-TASK.md) completed the README, identity assets, release-version consistency and planning
reconciliation; PR #133 merged into `develop` on 2026-10-21. Its earlier v0.8.0 recommendation is now superseded by
John's version selection above. The breaking pre-v1 Role and recovery contracts require a new minor, not a v0.7.x
patch. The [Board](tasks/BOARD.md) owns the current TASK portfolio.

## Published v0.7.0

[v0.7.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.7.0) was published on 2026-10-20
from certified main merge `cf55a25` through [PR #124](https://github.com/johnnickell/fight-access-control/pull/124).
Independent release-content acceptance, exact-main certification, human signed tag, immutable non-prerelease
GitHub publication (latest at its publication checkpoint) and matching Packagist source/dist projection are verified. The
[release overview](../docs/release-0.7.0.md) owns the breaking contracts and consumer obligations. Merge-back into
`develop` completed through [PR #125](https://github.com/johnnickell/fight-access-control/pull/125) on 2026-10-20.
The ignored release ledger retains cleanup evidence; branch cleanup is not inferred from merge state.
The [Board](tasks/BOARD.md) owns current implementation status. Consumer qualification, enablement/adoption and
deployment, and the v1.0.0 stability decision, remain separate.

### Historical v0.7.0 preparation checkpoint

John selected **v0.7.0** and the main checkout on 2026-10-20. Refreshed `develop` `4ffc31b` contains the complete
independently accepted registration capability integrated through PRs #121–#123. The
[release overview](../docs/release-0.7.0.md) covers durable activation origin, atomic default-Role registration,
authorized administrator recovery and the required breaking consumer contracts. PHP 8.5+ and Common ^1.3 remain
unchanged; release preparation changes documentation only. Independent release-content acceptance, release PR/main
integration, exact-merge certification, human signing and separately authorized publication remain required.
No implementation TASK is unfinished. The EPIC's pre-v1.0.0 planning destination does not select v1.0.0 or waive
its separate stability review; consumer qualification, enablement and deployment remain separate.

The v0.6.0 release was published on 2026-10-19 and merged back through
[PR #120](https://github.com/johnnickell/fight-access-control/pull/120). Earlier preparation and implementation
checkpoints below retain their historical meaning; the Board owns current implementation status.

### Historical v0.6.0 preparation checkpoint

John selected **v0.6.0** and the main checkout on 2026-10-19. Integrated `develop` `cc364511` includes merged
[PR #118](https://github.com/johnnickell/fight-access-control/pull/118) and accepted
[TASK-00076](tasks/00076-TASK.md). The [release overview](../docs/release-0.6.0.md) documents expiry-aware invitation
retry and the breaking required Clock constructor argument. No runtime/test/dependency change is part of release
preparation. Independent release-content acceptance, main integration, exact-merge certification, human signing and
separately authorized publication remain required. Optional self-registration TASK-00073–00075 remains planned
v1.0.0 work; the Board owns its execution selection and it does not block this bounded release.

The v0.5.0 release was published on 2026-10-06 and merged back through PR #115. The sections below preserve prior
implementation/release-readiness checkpoints, not current publication or portfolio state. Package release does not
establish actual consumer qualification, dependency adoption or deployment.

### Historical v0.5.0 preparation checkpoint

John selected **v0.5.0** and the main checkout on 2026-10-06. Integrated `develop` `4163e58` contains the accepted
Agent-operation, human-expiry, Feature and Agent-profile/MCP work; no implementation TASK remains open. The
[release overview](../docs/release-0.5.0.md) owns the combined contract and consumer obligations. Dated release notes
are preparation, not a release receipt: independent release-content review, integration into `main`, exact-merge
certification, human signing and separately authorized publication remain outstanding at this checkpoint.

Earlier implementation/landing checkpoints below retain their historical meaning. Actual consumer qualification,
dependency adoption and deployment remain separate from package acceptance and release.

## Human delivery expiry readiness prerequisite

The required [human credential-delivery expiry capability](wayfinder/human-credential-delivery-expiry-map.md) now
has approved upstream records, complete implementation/local verification, independent acceptance and applicable
behavioral QA. This package prerequisite for v0.5.0 is satisfied; final release certification is still required.
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

## Historical Agent package acceptance checkpoint

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

## MCP package acceptance

Fight Common ^1.3 supplies the released Tool API; inspected v1.3.0 revision/signatures and limits are recorded in
[TASK-00035](tasks/00035-TASK.md#verified-fight-common-v130-contracts). TASK-00035 and TASK-00069–00071 now have
independent technical acceptance and behavioral QA and are integrated through PR #113. EPIC-00005/00011 and their
TICKETs are done. Both destinations are included in v0.5.0 preparation; no TASK is waiting on the former Common gate.
The related Wayfinder map remains Closed. Actual consumer MCP runtime, security, seed/grant and persistence
qualification remain separate; package acceptance is not publication or adoption.

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
authorized TASK-00067 landing; publication remained pending at that tracked checkpoint. Its PR #107 is now merged
into the selected release baseline. The complete Feature capability is included in v0.5.0 preparation; certification,
publication, actual consumer adoption and deployment remain separate.

## Optional self-registration accepted for v0.7.0

[EPIC-00013 — Optional User Self-Registration and Registration-Origin Delivery for v1.0.0](epics/00013-EPIC.md)
records John's approved opt-in package destination after the
[Wayfinder map](wayfinder/self-registration-v1-map.md) closed WF-026–028. Consumers own enablement, public protections
and email templates; package registration must keep atomic uniqueness/default Roles and persist registration versus
private-invitation delivery origin through recovery. No v0.5.0 scope or default Agent OS/starter features change.
John approved the three-area requirement decomposition: [TICKET-00021](tickets/00021-TICKET.md) (delivery origin) →
[TICKET-00022](tickets/00022-TICKET.md) (registration) → [TICKET-00023](tickets/00023-TICKET.md) (administrator
recovery). John approved one TASK per requirement area:
[TASK-00073](tasks/00073-TASK.md) (origin/provider) → [TASK-00074](tasks/00074-TASK.md) (atomic registration) →
[TASK-00075](tasks/00075-TASK.md) (authorized correction and expired reissue). All three tasks are accepted with
applicable nonvisual QA and integrated through PRs #121–#123; final
[PR #123](https://github.com/johnnickell/fight-access-control/pull/123) is merged into `develop` `4ffc31b`.
Automatic parent completion closed TICKET-00023 and EPIC-00013. The complete capability is now published in
[v0.7.0](../docs/release-0.7.0.md); consumer integration/enablement and deployment remain separate.
The EPIC's historical pre-v1.0.0 target is not an authorization to release v1.0.0.

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
| [EPIC-00012](epics/00012-EPIC.md) | `v0.5.0` | Human expiry accepted with behavioral QA and integrated through PR #108; the package release prerequisite is satisfied. Actual PostgreSQL/consumer qualification, adoption, certification/publication and deployment remain separate. |
| [EPIC-00011](epics/00011-EPIC.md) | `v0.5.0` | Agent rename, minimal profiles and protected self-service Tools accepted with behavioral QA and integrated through PR #113; consumer qualification remains separate. |
| [EPIC-00010](epics/00010-EPIC.md) | `v0.5.0` | All seven Feature TASKs accepted with behavioral QA; final lifecycle evidence integrated through PR #107. Consumer qualification and publication remain separate. |
| [EPIC-00009](epics/00009-EPIC.md) | `v0.5.0` | Agent operations, current-contract cleanup and cohort/canonical/restoration safety accepted; final guidance has independent acceptance and QA. Real consumer qualification and release remain separate. |
| [EPIC-00005](epics/00005-EPIC.md) | `v0.5.0` | Agent-protected MCP authorization accepted with behavioral QA and integrated through PR #111; consumer runtime qualification remains separate. |
| [EPIC-00006](epics/00006-EPIC.md) | `v0.3.0` | Published the recoverable credential-delivery contract and migration after exact-commit certification and signing. Consumer upgrades remain separately qualified. |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | Replaced deprecated Fight Common transaction dependencies with the supported `TransactionalUnitOfWork` contract while preserving established behavior. |
| [EPIC-00004](epics/00004-EPIC.md) | `v0.2.0` | Delivered the local consumer-composable OpenAPI schema review candidate; tag, release, and publication remain separate effects. |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | Delivered the final unified User/Agent `SecurityContext` and retry-safe Agent Permission, User Role, and custom-Role Permission changes. |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

| Version | Date | Outcome |
| --- | --- | --- |
| `v0.8.0` | 2026-10-21 | Published breaking Role caller revisions and recovery composition, operation-outcome and managed-policy consistency, plus contributor/release-documentation improvements. Main-to-develop merge-back completed through PR #135; release-owned cleanup is satisfied. Consumer qualification remains separate. |
| `v0.7.0` | 2026-10-20 | Published optional User registration, activation origin and administrator recovery; merged back through PR #125. Consumer enablement and qualification remain separate. |
| `v0.6.0` | 2026-10-19 | Published expiry-aware invitation retry and the required Clock composition change; merged back through PR #120. |
| `v0.5.0` | 2026-10-06 | Published recoverable Agent operations, human expiry, Features and Agent-protected profile Tools; consumer qualification remains separate. |
| `v0.4.0` | 2026-09-27 | Published protected Permission tiers, reserved Super Admin identity and atomic eligible delegation/reconciliation; consumer integration remains separately qualified. |
| `v0.3.0` | 2026-09-25 | Published recoverable, provider-neutral credential delivery for invitations, password resets, and email changes, with pre-1.0 breaking migration guidance. |
| `v0.2.0` | 2026-09-13 | Published consumer-composable OpenAPI schema components. |
| `v0.1.0` | 2026-09-10 | First public package milestone: framework-neutral User and Agent identity, authentication, session, Role, Permission, managed-policy, and current-authority behavior with exact coverage. |

## Record Status Projection

<!-- generated:epic-status:start -->
| EPIC | Target | Status | Tickets | Tasks |
| --- | --- | --- | --- | --- |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | done | 2 | 22 |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | done | 1 | 7 |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | done | 2 | 6 |
| [EPIC-00004](epics/00004-EPIC.md) | v0.2.0 | done | 1 | 1 |
| [EPIC-00005](epics/00005-EPIC.md) | v0.5.0 | done | 1 | 1 |
| [EPIC-00006](epics/00006-EPIC.md) | v0.3.0 | done | 1 | 3 |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | done | 1 | 1 |
| [EPIC-00008](epics/00008-EPIC.md) | v0.4.0 | done | 3 | 5 |
| [EPIC-00009](epics/00009-EPIC.md) | v0.5.0 | done | 3 | 14 |
| [EPIC-00010](epics/00010-EPIC.md) | v0.5.0 | done | 3 | 7 |
| [EPIC-00011](epics/00011-EPIC.md) | v0.5.0 | done | 2 | 3 |
| [EPIC-00012](epics/00012-EPIC.md) | v0.5.0 | done | 1 | 1 |
| [EPIC-00013](epics/00013-EPIC.md) | v1.0.0 | done | 3 | 3 |
| [EPIC-00014](epics/00014-EPIC.md) | unassigned | ready-for-agent | 8 | 23 |
<!-- generated:epic-status:end -->
## Current delivery handoffs

| Subject | Recorded delivery | Exact PR |
|---|---|---|
| [TASK-00086](tasks/00086-TASK.md) | awaiting-merge; verify live state | [https://github.com/johnnickell/fight-access-control/pull/141](https://github.com/johnnickell/fight-access-control/pull/141) |
