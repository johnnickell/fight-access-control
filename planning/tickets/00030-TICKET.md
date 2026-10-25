---
id: TICKET-00030
epic: EPIC-00014
title: Authorize retained notices and live Subscriptions
status: ready-for-agent
---

# Authorize retained notices and live Subscriptions

## Problem and Outcome

An initial listen/read grant cannot authorize every future notice, catalog signal, subscriber record or broadcast.
Consumers need reusable Agent Permission composition for Common's retained-notice and Subscription boundaries,
while keeping topic vocabulary, relationships, recipients and publication policy in the application.

Cover recording/removal, retained browsing/polling/recovery, listening, queued/live disclosure, supported catalog
signals, subscriber inspection and publication admission where composed. Keep read, mutation, inspection and
publication authority distinct. This is item 6 of [EPIC-00014](../epics/00014-EPIC.md).

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Trusted producer records a notice or authorized caller removes retained notices | Owning Common Commands and their existing events | Apply independent current operation authority/scope before mutation, including repeat/missing-target paths; integration adds no second store or grant. |
| Agent browses, polls or recovers retained notices | Owning Common Queries and explicitly exposed polling Tool where composed | Admit each summary/page/detail/recovery call and protect notices, totals, relationship/history-loss metadata and continuation context. |
| Agent opens a live Subscription | Actual Common protocol/registration lifecycle | Admit supported permitted filters using trusted caller context; opening establishes no continuing permission lease. |
| Runtime is ready to emit a queued notice or catalog-change signal | Common owns protocol notification delivery | Recheck current authority/scope before disclosure; a small signal must not reveal otherwise concealed activity. |
| Authorized caller inspects current subscribers | Owning Common Queries | Apply separate inspection authority to records, totals, identities, filters and freshness metadata. |
| Consumer requests publication, including optional private Mercure output | Owning Common publication seam | Apply independent publication/topic/disclosure policy; denied private publication cannot fall back to public delivery. |

AccessControl introduces no notice CRUD use case, business event, stream registration database or broadcast engine.
Allowed operations retain Common's actual mutation/events/publication behavior. Permission integration must not
claim recording/delivery/receipt, undo committed notices after publication failure or invent processing evidence.

## Requirements, Validation and Permissions

- Bind actual supported Common admission/disclosure ports to configured direct Agent Permissions and consumer
  authoritative scope. Keep producer/record, removal, read/recovery, listen, disclosure, inspection and publication
  checks independent as applicable; no generic listen/read Permission automatically enables other operations.
- Subjects, intended recipients, authors, causation IDs, topics, role labels and heartbeat state are protected data,
  not credentials or grants. Human operator access does not automatically grant Agent access.
- Use [TICKET-00028](00028-TICKET.md) for current live/background authority, including queued disclosure and scope
  changes after opening. No stale request-provider singleton, nonce replay, silent credential switching or cached allow.
- Protect catalog-change signals themselves. Tool/Resource/Skill/Prompt admission is evaluated for relevant enabled
  families without constructing protected bodies merely to decide whether a notification may be emitted.
- Reauthorize every retained read/recovery request, not just the initial list. Preserve actual Common completeness,
  access-continuity and history-loss semantics: an empty page or earlier access is not complete catch-up evidence.
- Enforce finite supported filter/selection/output/stream boundaries through Common; no hidden records/counts,
  private topic names or unauthorized metadata in cursors, acknowledgments, failures or diagnostic output.
- Keep safe notices separate from protected message/tool bodies and full Task state. Task-specific state disclosure
  belongs to TICKET-00031; generic change-feed access is insufficient.
- Preserve recorded-data recovery after stream/publication failure. Reconnect establishes a newly admitted stream;
  opening, freshness or recipient matching proves neither processing nor exactly-once receipt.
- Reuse Common's protocol and safe-error boundaries. Publication/mutation/data faults must not masquerade as an
  ordinary permission allow/denial or successful healthy stream. No raw exception output or duplicate log owner.

## Dependencies and External Gates

- TICKET-00028 supplies current authority checks for repeated disclosures/continuations.
- [TICKET-00025](00025-TICKET.md), [TICKET-00026](00026-TICKET.md) and [TICKET-00027](00027-TICKET.md) supply relevant
  Tool, Resource/Skill and Prompt family checks when those catalog notifications are actually composed. Do not
  invent a dependency on an unavailable family for an otherwise independent supported operation.
- Polling Tool paths use TICKET-00025 where composed; retained reads/polling remain usable without live Subscriptions.
- Common owns actual notice Commands/Queries, admission ports, storage, recovery, Subscription runtime and optional
  Mercure translation. Verify implemented/independently accepted/qualified/tagged contracts and minimum versions
  before affected integration/adoption. Common planning or uncommitted removal code is not a stable PHP contract.
- [TICKET-00031](00031-TICKET.md) consumes this live path only for optional Task-specific notifications; ordinary
  Task operations/polling have no forced Subscription dependency. No reverse dependency is introduced.

## Acceptance Evidence

- [ ] Actual Common use cases/protocol fixtures compose with real AccessControl authority checks and controlled
  consumer scope; each operation has separate allow/deny/fault evidence, including repeats and missing targets.
- [ ] Summary/pages/details/recovery protect rows, totals, identities, continuation and history-loss facts under
  access changes; no fabricated complete catch-up or safe-empty replacement of a storage/policy failure.
- [ ] Live opening/filter admission and queued emission recheck current authority, credential and scope changes;
  deny before both payload and concealed-activity signals, with no stale cached decision or replayed nonce.
- [ ] Independent subscriber inspection and publication prove no listen/read-to-global-inspection/broadcast grant,
  no private-to-public fallback, no bodies/full Task state in generic notices and preserved Common recovery semantics.
- [ ] Catalog-family composition, optional/Mercure-free paths, actual finite encoded/live delivery and error/reporting
  boundaries are qualified against selected Common implementations, not simulated unsupported protocol flags.
- [ ] Minimum-version evidence, documentation, exact owned coverage, full package gate and later independent Review/
  behavioral QA distinguish package/runtime fixtures from actual deployed consumers, hubs and client receipt.

## Exclusions

Notice storage/retention engines, producer directories, topic policy, transport management, subscriber persistence,
Mercure/hub operations, full Task state, consumer capture/redaction policy, receipt guarantees and deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 91 | [TASK-00091](../tasks/00091-TASK.md) | Authorize notice recording and bounded removal | needs-info |
| 92 | [TASK-00092](../tasks/00092-TASK.md) | Authorize retained inspection and polling recovery | needs-info |
| 93 | [TASK-00093](../tasks/00093-TASK.md) | Authorize live Tool, Resource and whole-Skill change disclosure | needs-info |
| 94 | [TASK-00094](../tasks/00094-TASK.md) | Authorize Prompt catalog-change disclosure | needs-info |
| 95 | [TASK-00095](../tasks/00095-TASK.md) | Authorize current-subscriber inspection | needs-info |
| 96 | [TASK-00096](../tasks/00096-TASK.md) | Authorize optional notice publication | needs-info |
## Decisions and Progress

John approved item 6 of the eight-TICKET split, explicitly invoked To Tasks for this requirement, then approved
six complete capability journeys. The full accepted decomposition is written:

| Approved slice | TASK | Direct local blockers |
| --- | --- | --- |
| A — Recording and bounded removal | [TASK-00091](../tasks/00091-TASK.md) | TASK-00088 for included background producer/cleanup admission |
| B — Retained inspection and polling recovery | [TASK-00092](../tasks/00092-TASK.md) | TASK-00084 for the complete polling Tool journey |
| C — Live Tool/Resource/whole-Skill change disclosure | [TASK-00093](../tasks/00093-TASK.md) | TASK-00086, TASK-00088, TASK-00092; Resource/Tool suppliers transitively |
| D — Prompt catalog-change disclosure | [TASK-00094](../tasks/00094-TASK.md) | TASK-00087, TASK-00093 |
| E — Current-subscriber inspection | [TASK-00095](../tasks/00095-TASK.md) | TASK-00093 for protected opening → independent inspection |
| F — Optional notice publication | [TASK-00096](../tasks/00096-TASK.md) | TASK-00088, TASK-00092; no live/Prompt/inspection dependency |

All six are needs-info for independently accepted/qualified tagged Common contracts and supported-minimum evidence,
in addition to their local supplier edges. Source recording/removal/read ports, planned recovery/live/publication,
and existing raw Mercure publishers are not interchangeable proof of the required qualified integration. Each TASK
owns real authority/Common composition, safe allow/deny/fault evidence, tests/docs/minimum qualification, exact
coverage/full gate and independent Review/behavioral QA; no layer-only or verification-only follow-up is planned.

Keep mutation/read/listen/disclosure/inspection/publication authority independent. Source-reading scope is not
recipient disclosure authority; reusing shared Queries does not silently require a listener's public browse Permission.
Skills use actual Resource boundaries, not invented protocol flags. Prompt absence does not block ordinary live
families; Mercure remains independent. Ordinary reads/polling need no live enablement; Task-specific state stays
with TICKET-00031 and ordinary Task polling has no forced Subscription dependency.

This TICKET's TASK planning is complete, not implementation. TICKET-00031's approved TASK-00097–00102 plan is also
written, as is TICKET-00032's approved TASK-00103–00106 plan. All EPIC-00014 map planning phases/full TASK plans are
accepted/complete; consult the Board for execution selection, not automatic implementation.
Map stays Closed; preserve one combined release/all five frameworks, opt-in runtime capabilities and unassigned
version. No source/dependency/config/grant changes, implementation, publication, adoption or release is authorized.
Choose the intended checkout before separately invoked execution; actual consumer scope/storage/lifetime/race and
hub/client qualification remains separate from package fixtures.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
