---
id: TICKET-00031
epic: EPIC-00014
title: Authorize durable Task operations and state disclosure
status: ready-for-agent
---

# Authorize durable Task operations and state disclosure

## Problem and Outcome

Starting an asynchronous operation or knowing its Task handle does not authorize every read, input answer,
cancellation, producer update or later full-state notification. Consumers need reusable independent Agent
Permission checks around Common's actual durable Task lifecycle and current-authority boundaries.

Protect applicable start/create, trusted producer update, read/result/input disclosure, answer admission,
cancellation, removal and protected continuation. Optional full-state live output has Task-specific current
permission, not merely generic change-feed access. This is item 7 of [EPIC-00014](../epics/00014-EPIC.md).

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Agent starts an explicitly supported asynchronous Tool operation | Owning Common Task creation/use case, after Tool admission where applicable | Separate start/create authority from later state access; retain actual negotiation/handle durability behavior. |
| Trusted producer supplies a lifecycle update | Owning Common producer operation/events | Require current operation/scope authority; no generic public status setter or actor identity supplied by protocol data. |
| Agent gets status/result or outstanding input | Owning Common read Query / `tasks/get` boundary | Reauthorize each read/reconnect; protect state, payloads, request definitions and metadata independently of handle possession. |
| Agent submits requested input or confirmation responses | Owning Common input Command / `tasks/update` boundary | Admit current binding/scope before protected validation and atomic input admission; no authority from a previous answer/read. |
| Agent requests cancellation or authorized cleanup removes Task data | Owning Common cancellation/removal operations | Independent current mutation authority on repeats/terminal/missing paths; no unauthorized outcome disclosure. |
| Worker continues protected work or runtime emits full Task state | Owning continuation/disclosure contract, not a new AccessControl job | Recheck current authority/scope at each applicable boundary; never use a generic notice grant for protected Task state. |

The integration adds no lifecycle database, execution Command, scheduler or checking event. Owning Common use cases
retain their atomicity/events, terminal-state, retained input, confirmation, cancellation and truthful absence rules.
Cancellation permission/acknowledgment does not prove stopping, rollback or a `cancelled` terminal outcome.

## Requirements, Validation and Permissions

- Compose configured direct Agent Permissions with trusted consumer Task ownership/sharing/scope through actual
  supported Common ports. Keep creation, producer update, read/disclosure, input, cancellation, removal and
  continuation independently admitted as applicable; one grant cannot stand for all operations.
- Consumer identity, scope and original operation binding are authoritative. Task/job/Workflow/planning references,
  capability negotiation, recipient fields, prior creation and administrator labels grant nothing by themselves.
- Protect result/error/input payloads and state/revision/filter metadata. Unknown/denied/cross-scope cases must not
  reveal concealed presence, conflict material, original arguments, accepted answers or private diagnostics.
- Reauthorize reads and mutations even on repeat/no-op/terminal/missing records; retain actual protocol distinctions
  without turning safe failure into fabricated empty/successful state.
- Use [TICKET-00028](00028-TICKET.md) for protected continuation and repeated live disclosure, preserving original
  credential binding and current Permissions/scope; no request snapshot held indefinitely or replayed nonce.
- Input authority is not continuation authority. Preserve Common's caller/Task/operation/request/group/expiry
  confirmation binding and atomic admission; partial or refused answers do not authorize destructive continuation.
  The binding must not restore consumed admission or invent exactly-once external execution after failure.
- Keep ordinary status/result polling independent of live Subscriptions. When live output is composed, apply the
  same sensitive Task-state disclosure checks before queued/baseline/updated state and preserve Common's ordering,
  revision and current-state recovery semantics. A generic notice/topic grant is insufficient.
- Full Task state stays out of the general retained safe-notice feed and indiscriminate Mercure output. No
  alternative redacted/partial successful Task result may bypass the actual protocol's complete-state contract.
- Common owns supported state/input/cancel validation, negotiation, bounded payloads and error envelopes. Use the
  existing MCP-specific safe boundary; do not inject HTTP/JSend errors or copy raw exception messages.

## Dependencies and External Gates

- TICKET-00028 supplies repeated current-authority checks. [TICKET-00025](00025-TICKET.md) supplies applicable
  Tool-start admission only; direct Task operation/read authority remains independently checked.
- [TICKET-00030](00030-TICKET.md) supplies live Subscription composition solely for optional full-state notifications.
  Neither Task polling nor ordinary input/cancellation has a forced live dependency; no reverse blocker is added.
- Verify Common's actual implemented/accepted/qualified/tagged Task lifecycle, input, cancellation, removal and
  optional live contracts, plus minimum version before affected implementation/adoption. Do not create guessed PHP
  signatures, a partial advertised extension or a local lifecycle fork from approved Common planning.
- Consumer execution, sharing policy, storage, workers and actual client/runtime qualification remain external.
  The combined release waits for all selected scope; runtime capabilities and transport choices remain opt-in.

## Acceptance Evidence

- [ ] Real qualified Common Task operations compose with actual AccessControl authority and controlled consumer
  scope, including independent allow/deny/fault cases for every applicable operation and repeat/no-op path.
- [ ] Allowed Tool start does not imply read/input/cancel/update/remove access; cross-caller/Task/operation and
  forged metadata/handle use cannot disclose protected state or consume another caller's input/confirmation.
- [ ] Current input admission and continuation remain separate under changed authority, partial/refused inputs,
  failures/retries and terminal/cancel races without restoring admission or claiming exactly-once effects.
- [ ] Polling/reconnect works without live delivery; actual optional full-state delivery rechecks authority before
  queued/baseline output and preserves ordering/current-state recovery, protected payload bounds and feed isolation.
- [ ] Revocation/credential/Permission/scope changes or resolver faults deny the next protected boundary without
  silently switching credentials, replaying a nonce, rolling back old effects or inferring that a worker stopped.
- [ ] Minimum-supported dependency evidence, safe diagnostics, documentation, exact coverage, canonical gate and
  later independent Review/behavioral QA distinguish library proof from actual deployed worker/client qualification.

## Exclusions

Task execution/scheduling/storage, application Workflow/Review state, ownership/sharing policy, public status setters,
forced cancellation, receipt/processing/rollback guarantees, consumer migration and deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 97 | [TASK-00097](../tasks/00097-TASK.md) | Authorize Task creation, producer updates and protected reads | needs-info |
| 98 | [TASK-00098](../tasks/00098-TASK.md) | Authorize bounded retained Task removal | needs-info |
| 99 | [TASK-00099](../tasks/00099-TASK.md) | Authorize durable input and protected continuation | needs-info |
| 100 | [TASK-00100](../tasks/00100-TASK.md) | Authorize cancellation requests and intent observation | needs-info |
| 101 | [TASK-00101](../tasks/00101-TASK.md) | Authorize complete MCP Task start and reconnect journeys | needs-info |
| 102 | [TASK-00102](../tasks/00102-TASK.md) | Authorize optional full-state Task notifications | needs-info |
## Decisions and Progress

John approved item 7 of the eight-TICKET split, explicitly invoked To Tasks for this requirement, then approved
six complete capability journeys. Its full accepted decomposition is now written:

| Approved slice | TASK | Direct local blockers |
| --- | --- | --- |
| A — Creation, producer updates and protected reads | [TASK-00097](../tasks/00097-TASK.md) | TASK-00088 for included background/continuation admission |
| B — Bounded retained Task removal | [TASK-00098](../tasks/00098-TASK.md) | TASK-00097 |
| C — Durable input and protected continuation | [TASK-00099](../tasks/00099-TASK.md) | TASK-00097; TASK-00088 transitively |
| D — Cancellation requests and intent observation | [TASK-00100](../tasks/00100-TASK.md) | TASK-00097, TASK-00099 for real input/intent races |
| E — Complete MCP start/reconnect journeys | [TASK-00101](../tasks/00101-TASK.md) | TASK-00084, TASK-00097, TASK-00099, TASK-00100 |
| F — Optional full-state notifications | [TASK-00102](../tasks/00102-TASK.md) | TASK-00093, TASK-00101 |

All six are needs-info for implemented/independently accepted/qualified tagged Common contracts and supported-minimum
evidence, separate from local supplier edges. Each owns real authority/Common composition, independent allow/deny/
fault and effect proof, tests/docs/minimum qualification, exact coverage/full gate and independent Review/behavioral
QA. No layer-only or verification-only follow-up is planned; concrete PHP APIs follow qualified implementations.

Producer authority does not substitute for the original caller's continuation authority. Handle-response disclosure
and ongoing state/input/cancel rights remain independent of start/create. Input acceptance never restores consumed
confirmation or grants continuation; cancellation intent/acknowledgment proves no stopping/rollback/terminal outcome.
B does not block E, and application/input/cancel suppliers do not wait for complete MCP activation or advertise a
partial extension. Ordinary polling/input/cancellation need no live enablement. F has no extra removal/Prompt/
subscriber-inspection/Mercure blocker; full state stays outside generic notice/publication/tracking payloads.

This TICKET's TASK planning is complete, not implementation. TICKET-00032's approved TASK-00103–00106 plan is also
written; all EPIC-00014 map planning phases/full TASK plans are accepted/complete. Consult the Board for execution
selection, not automatic implementation. Map stays Closed; preserve the one combined release/all five frameworks, opt-in
capabilities and unassigned version. No implementation/source/dependency/config/grant change, commit, publication,
adoption or release is authorized. Choose the intended checkout before separately invoked execution. Actual consumer
storage/scope/authority-writer fences, workers, cryptography and client/runtime qualification remain separate.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
