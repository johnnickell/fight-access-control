---
id: TICKET-00034
epic: EPIC-00015
title: Consume supported canonical Fight Common contracts
status: ready-for-agent
---

# Consume supported canonical Fight Common contracts

## Problem and Outcome

Common's approved foundation namespaces, repository/UoW separation, handler ownership and typed identities are
changing. Adding integrations against deprecated names or provisional lifecycle/Store APIs would create avoidable
rework. Adopt actual supported canonical contracts for existing AccessControl composition and establish a precise
consumed-contract inventory for subsequent features under [EPIC-00015](../epics/00015-EPIC.md).

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Maintainer adopts a released canonical Common API | Existing owned messages/facts | Direct canonical imports and types with unchanged owned behavior; necessary manifest minimum only. |
| Integrator composes current MCP/profile/native capabilities | Existing invocation/bus operations | Existing authorization, outputs, lifetimes and failure contracts work at the supported minimum. |
| Builder implements a remaining Common-backed feature | Actual owning Common Commands/Queries/Events | Use real handlers/buses, repositories/read ports and separate UoW, not copied workflows or provisional Stores. |
| Supplier evidence is not yet available | N/A | Hold only affected adoption; no guessed version or stable claim from local code/planning. |

## Requirements and Validation

- TASK-00110 is the complete current-package adoption slice, including an inventory of every consumed contract
  affected by Common refactors, canonical destination/signature, actual tagged evidence, supported minimum,
  affected AccessControl callers/tests/docs and remaining per-feature qualification.
- Reinspect Common TASK-00181 and TASK-00184–00191, actual source, tags and independent evidence when invoked.
  Common's inspected local `9e46565633a2ee44fefacf27fc97b6a711467190` plus concurrent changes are orientation only.
  Planned McpProgressReporter placement under Tool is a target, not an available released import.
- Only adopt implemented/independently accepted/qualified, actually tagged APIs. Change Composer constraints
  deliberately when needed and prove exact supported-minimum behavior, not just a newer local checkout.
- Use supported upstream Application/Domain contracts directly. Do not wrap Common use cases in AccessControl
  aliases or use final Tool subclasses/schema copies. Common Task/Notice integrations consume separate repository/
  read and existing TransactionalUnitOfWork contracts through actual buses, not retired lifecycle/Store surfaces.
- Do not conflate Common's 1.x released-name bridges with AccessControl policy: this pre-v1 package updates its
  callers directly and adds no old-name aliases, optional legacy composition or version-dispatch fallback.
- Preserve existing Command/Query/Event identities, safe outputs, validation, actual transaction/nonce effects,
  post-commit outcomes, optional frameworks, request isolation and independent consumer authorization.
- New execution/Prompt/Notice/Task/live capabilities stay with their existing feature TASKs. This ticket's minimum
  qualification does not certify those APIs, change their external holds or wait for every Common refactor.

## Dependencies and External Gate

TASK-00110 has order 13; its historical needs-info hold is resolved by verified published Common v1.5.0
source/tag/independent release evidence. It is now in-progress pending independent adoption Review/QA. There is no local TASK blocker and no cross-repository blocked_by ID. The
maintainer/implementation owner verifies source/tag/minimum evidence before changing readiness; Common planning,
local completion and deprecation bridges are not proof of a canonical release.

The pending MCP roots TASK-00084/00087/00091/00097 consume its canonical current-package baseline and independently
qualify their new family/operation contracts. Native TASK-00105/00106 can proceed on their actual qualified APIs
without this MCP migration. Unavailable unrelated supplier work never blocks all local development.

## Acceptance Evidence

- [ ] A source-linked inventory accounts for affected consumed names, signatures, construction, interfaces,
  messages/value serialization and owned references, with precise implemented versus planned versus tagged status.
- [ ] Real current Tool/Resource/Skill, profile, authority and optional native composition works through canonical
  APIs at the sufficient supported minimum; exact package/source/runtime evidence and necessary manifest changes exist.
- [ ] Owned imports/docs/examples no longer use affected deprecated APIs; no legacy wrappers or shadow workflows
  are added. Future feature suppliers/holds remain explicit rather than certified by this refactor.
- [ ] Denial-before-work, sensitive-error handling, request scopes, existing outcomes and wire/persistence shapes
  remain unchanged; any actual incompatible semantic change returns for scoped disposition rather than being hidden.
- [ ] Focused checks, exact statement coverage/full gate, complete local receipts and independent Review/QA qualify
  this package integration, not actual consumer storage/cryptography/bootstrap/deployment.

## Exclusions

Editing Common/consumer repositories, implementing Common plans, adopting development snapshots as stable,
forcing every future API into one minimum, guessed release numbers, AccessControl compatibility shims, grants,
new feature implementations, release and deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 13 | [TASK-00110](../tasks/00110-TASK.md) | Adopt qualified canonical Fight Common contracts | in-progress |
## Decisions and Progress

John approved one complete TASK-00110 for this requirement. Work has now qualified the supplier v1.5.0 baseline
and implemented current-package adoption; independent adoption acceptance remains required. See the
[contract inventory](../../docs/common-contracts.md). Local refactoring and ordinary native-framework work remain independent. The existing combined EPIC-00014 release scope
and unassigned version are unchanged. Planning creates no dependency adoption or publication authority.

Next: independent Review and behavioral QA of TASK-00110's committed adoption and retained gate evidence;
new MCP features retain their own supplier/operation qualification.
