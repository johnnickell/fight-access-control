---
id: TICKET-00051
epic: EPIC-00018
title: Enforce portable Permission declarations across five frameworks
status: ready-for-agent
---

# Enforce portable Permission declarations across five frameworks

## Problem and Outcome

Under [EPIC-00018](../epics/00018-EPIC.md), connect AccessControl-owned portable RequiresPermission declarations to
existing inward Permission admission across Slim, Yii 3, Symfony, Laravel and CodeIgniter 4. Consumers gain consistent
class/method/inherited requirements without importing Agent OS's class-only Attribute or duplicating policy.

## Use Cases and Contracts

| Actor and trigger | Observable outcome |
| --- | --- |
| Integrator selects portable Permission admission only | Optional native integration without Feature middleware/evaluation or unrelated service activation. |
| Routing selects an attributed action or explicit trusted binding | TICKET-00049's complete effective conjunctive requirements drive the existing declaration/admission contracts. |
| Trusted User or Agent reaches a protected action | Appropriate existing current authority checks every Permission before protected validation/action work. |
| Public/authenticated/protected route policy conflicts with attributes | Reject contradiction/malformed composition rather than weaken inherited requirements or infer public access. |
| Authentication, Permission or operational failure occurs | Existing qualified refusal/challenge/error behavior, with one safe reporting owner and no raw diagnostics. |
| Reused/nested request selects a new action | Fresh route/authority/context lifetime; no stale declaration or accidental grant propagation. |

No new business Command, Query, Domain Event or audit mutation is needed merely to check Permissions. Existing
required authentication effects remain distinct; adding an Attribute must not replay authentication or Agent nonces.

## Requirements and Validation

- Consume TICKET-00049's portable class/method/repeatable RequiresPermission and effective hierarchy/route semantics.
  Connect them to existing inward PermissionDeclaration/PermissionAdmission/authority behavior, not a second policy
  engine, copied grant list, role/display-name bypass, OAuth-scope conversion or generic target-aware policy port.
- Use native optional integration in all five families: shared PSR-15 with separately qualified Slim/Yii 3 routes,
  Symfony subscriber/listener, Laravel middleware and CodeIgniter 4 before-filter/response short-circuit. Keep actual
  capability-first placement, optional dependency isolation and inward architecture; no blanket PSR-15 wrapper for
  incompatible native lifecycles or silent global handler/container takeover.
- Resolve declarations for the actual selected action without requiring the same restriction to be redundantly
  declared in both an Attribute and a route. Additional explicit trusted route requirements accumulate; invalid,
  contradictory or ambiguous protected metadata rejects. The absence of an Attribute is not a public declaration.
  Preserve deliberate PUBLIC/AUTHENTICATED/PROTECTED semantics and actual mode validation.
- Reuse canonical PermissionName validation and existing trusted User/Agent current-authority contracts. Users have
  Role-derived Permissions; Agents have direct Permissions. Resolve one appropriate request authority without
  client-supplied snapshots, stale singleton providers, automatic identity fallback or replayed nonce effects.
  Consumer entry/target/scope policy and protected-tier membership rules remain distinct under ADR 0010.
- Permission admission is independently selectable without Feature reads or middleware. A PUBLIC Permission route
  may separately be Feature-gated; Feature ON never grants Permission. When both are composed, neither may erase
  the other's requirements/context or reinterpret a configuration fault as public/available success.
- Preserve ordinary authentication/authorization refusal behavior, supported consumer-selected valid challenges and
  actual type-based safe error policy. Unknown provider/storage/native faults are not guessed 401/403 by message,
  code/cause or projector status. Use selected Common-compatible native reporting/rendering, one redacted reporting
  owner and safe fallback; do not introduce competing renderers/loggers or claim delegated non-JSON is sanitized.
- Prove actual native ordering before protected validation/action work, route/action association and supported
  class/method/closure/invokable/inherited dispatch forms. Preserve qualified early admission and document actual
  eager/bootstrap/Symfony/Laravel construction limits; no universal pre-construction promise. Exercise sequential,
  subrequest/nested/reused-container and failure lifetimes, native response short-circuit and supported error paths.
- Coordinate native Symfony IsGranted as existing independent native authorization, not automatic role-to-Permission
  voter installation or replacement. Existing Agent-specific MCP Attribute/bindings remain separate. Consumer
  adoption of package RequiresPermission, including Agent OS edits, is separately authorized and qualified.

## Dependencies and Existing Work

TICKET-00049's approved [TASK-00171](../tasks/00171-TASK.md) supplies portable effective Permission declarations,
inheriting TASK-00170's shared pure selected-action/ancestry output, not Feature evaluation/discovery/repositories or
runtime activation. TICKET-00050 is not a dependency for independent Permission admission.
Reuse accepted TASK-00103/00104. TICKET-00032 and pending TASK-00105/00106 retain initial Laravel/CodeIgniter Permission
adapter ownership; this ticket owns the new portable-declaration integration, not a second base adapter implementation.
The approved plan below assigns actual native adaptations/supplier edges once against implemented contracts without
changing earlier scope/order/blockers, redoing native groundwork or creating blanket framework/Common/architecture waits.
TICKET-00052 owns cross-capability reconciliation, not deferred testing/documentation/gate for these complete paths.

Coordinate actual EPIC-00015/00016 capability/typing contracts without repeating refactors or introducing an ambient
principal resolver. No missing new Common mechanism is currently demonstrated. Verify actual tagged APIs, sufficient
supported minima and ordinary framework/Common composition at execution; name genuine affected gaps rather than guess
hooks/versions or invent supplier IDs. Apply ADR 0011 without aliases/legacy readers/modes/backfill; Common keeps its
own compatibility policy. Obtain applicable complete private criteria before implementation/review without disclosure
and explicitly choose the execution checkout.

## Acceptance Evidence

- [ ] All five real native paths consume portable effective class/method/inherited/route declarations through existing
  inward admission, including Attribute-only protected requirements and explicit route additions without weaker overrides.
- [ ] Real User/Agent permitted/denied dispatch and public/authenticated/protected/contradictory/malformed configuration
  retain existing semantics, no implicit public fallback, forged context, privileged-name bypass or extra Feature dependency.
- [ ] Native ordering, supported dispatch forms, response short-circuit and reused/nested/subrequest lifetimes prevent
  protected work and stale identity/target propagation without overstating controller-construction guarantees.
- [ ] Refusals versus unknown faults, consumer-selected challenges, negotiation/report/render/fallback failures and
  safe output/reporting are qualified; consumer bootstrap/non-JSON/downstream limitations remain explicit.
- [ ] Existing native work is reused, current callers/docs and dependency/minimum lanes align, and every executable
  slice has focused checks, full gate/exact coverage, complete evidence and independent Review/behavioral QA.

## Exclusions

Duplicate initial native adapter implementation, Feature evaluation, new authentication/grants/scope/role mapping,
automatic Symfony voter or MCP binding replacement, consumer bootstrap/persistence/schema/UI/data operations,
arbitrary PHP-call/CLI/worker/live enforcement, Common/sibling repair, compatibility/migration/backfill, release and
deployment. Package/native fixture proof is not actual installed consumer auth/storage/routes/sinks/runtime qualification.

## Approved TASK Plan and Native Ownership

John approved four complete ordinary ticketed feature TASKs, covering all five frameworks as 2 + 1 + 1 + 1:

| TASK | Order | Complete delivered outcome | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00176](../tasks/00176-TASK.md) | 505 | Portable Permission integration through existing PSR-15 admission, separately qualified with actual Slim and Yii 3 journeys. | TASK-00171, completed TASK-00103 |
| [TASK-00177](../tasks/00177-TASK.md) | 505 | Native Symfony trusted-target/subscriber integration, preserving early admission and independent IsGranted checks. | TASK-00171, completed TASK-00104 |
| [TASK-00178](../tasks/00178-TASK.md) | 505 | Portable declaration integration through TASK-00105's actual native Laravel Permission middleware and report/render lifecycle. | TASK-00171, TASK-00105 |
| [TASK-00179](../tasks/00179-TASK.md) | 505 | Portable declaration integration through TASK-00106's actual CI4 Permission filter/error bridge and native response short-circuit. | TASK-00171, TASK-00106 |

All four are ready-for-agent because scope is settled; unfinished actual supplier edges still hold execution. Equal
order 505 selects no relative native-framework priority. Unlike the Feature plan, no new shared runtime prefactor is
needed: TASK-00171 owns effective Permission composition, inherited TASK-00170 owns pure action/ancestry traversal and
existing inward PermissionAdmission owns enforcement. No new merger/policy engine, sibling blocker, Feature activation
or TASK-00172–00175/TICKET-00050 wait follows. Existing TASK scope/orders/blockers remain unchanged; retain completed
TASK-00103/00104 edges. No blanket architecture/typing/audit/Common wait or invented upstream supplier IDs.

TASK-00178 genuinely consumes TASK-00105's native Permission middleware, unlike TASK-00174's Feature-only Laravel
path which needs no such base adapter. TASK-00179 consumes TASK-00106's Permission filter and actual thin Common-backed
native error bridge. Response helpers alone are not a safe exception owner. Reuse accepted actual suppliers once and
coordinate shared source/fixtures/dependencies/docs without duplicating base adapters or broadening earlier scope.

Every slice owns actual trusted selected-action/route association and supported controller/class/method/invokable/
closure/inherited forms with explicit trusted bindings where necessary. Attribute-only requirements produce PROTECTED
without redundant route repetition; inherited/overridden-parent and protected route additions accumulate. Validate all
declarations before effective deduplication, preserving raw PermissionDeclaration list/type/mode/duplicate rejection.
PUBLIC/AUTHENTICATED-only conflicts reject, children cannot weaken parents and absent Attributes do not imply PUBLIC.
Malformed/missing/ambiguous protected composition fails before authentication/protected work, not as public fallback.
No client headers/parameters/copied context may select policy/authority, and metadata inspection must not itself require
controller construction. Preserve native early enforcement and document real eager/bootstrap/construction limitations.

Reuse complete trusted request-owned User Role-derived versus Agent direct Permission snapshots, existing known refusal/
challenge behavior and one appropriate authentication without replaying Agent nonces. Preserve sequential/nested/
subrequest/reused-container/error/response lifetimes, without stale target/provider, forged context or implicit parent
handoff. Permission-only performs no Feature evaluation/discovery/repository calls; PUBLIC Permission may separately
have Feature gates, and ON is no action grant. Cooperating gates retain both requirements/trusted contexts without
trusting incoming copies. ADR 0010 entry/target/scope and protected-tier membership boundaries remain separate; native
IsGranted and Agent-specific MCP bindings are not automatically replaced or installed.

Each path reuses actual Common-compatible native safe error composition with one redacted reporting owner, type-based
known refusal versus opaque fault classification, valid challenges/headers, finite safe output and qualified report/
projection/sink/encoding/render/fallback/negotiation behavior. Unknown faults are not guessed 401/403 by message/code/
cause or projector status. Domain failures stay Domain-owned. State downstream/delegated non-JSON limits honestly;
no competing renderer/logger, debug/raw fallback or MCP/OAuth envelope takeover. No checking business message, mutation
audit, transaction, new authentication/grant policy or Feature provisioning/scanner is added; normal required
authentication effects remain distinct.

Each slice owns necessary current callers/docs/examples/Unreleased, optional/core-only isolation, sufficient actual
tagged-minimum/ordinary composition, focused checks, canonical full gate/exact production coverage, complete logs/
exits/receipt/source/dependency/runtime/candidate identities and independent Review/nonvisual behavioral QA. Final
TICKET-00052 reconciliation cannot replace individual native acceptance; discovered supplier defects return to the
actual owner with regression-first repair and renewed acceptance. Package fixtures are not real consumer auth/storage/
scanner/bootstrap/sink/proxy/runtime qualification. Apply ADR 0011 directly without aliases/history readers/legacy
modes/migration/backfill; Common retains its own compatibility policy. Obtain complete applicable authorized private
criteria before implementation/review without disclosure, and explicitly choose the execution checkout.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 505 | [TASK-00176](../tasks/00176-TASK.md) | Integrate portable Permissions through PSR-15 | ready-for-agent |
| 505 | [TASK-00177](../tasks/00177-TASK.md) | Integrate portable Permissions in Symfony | ready-for-agent |
| 505 | [TASK-00178](../tasks/00178-TASK.md) | Integrate portable Permissions in Laravel | ready-for-agent |
| 505 | [TASK-00179](../tasks/00179-TASK.md) | Integrate portable Permissions in CodeIgniter 4 | ready-for-agent |
## Decisions and Progress

John approved the requirement split and subsequently this complete four-TASK plan, now TASK-00176–00179 at equal order
505 with exact direct suppliers 00171+00103, 00171+00104, 00171+00105 and 00171+00106. This allocates all five native
families as 2 + 1 + 1 + 1, not implemented/native/supplier/consumer qualification or independent acceptance. Readiness
means settled scope, not supplier completion. Base Laravel/CI4 TASKs remain planned and unchanged; completed historical
edges remain. TICKET-00049/00050 are already TASK-planned, not implemented/qualified; neither runtime capability needs
the other. First Board selection remains TASK-00107.

Planning only: no source/dependency/Common/consumer mutation, product build, new checkout/branch/worktree,
commit/publication, release/version selection or deployment was performed.

Planning generation/read-only validation passes (248 records, 122 active), with documentation links and diff/new-record
whitespace checks. Metadata confirms four ordinary ticketed features, equal order 505 and exact direct suppliers
00171+00103, 00171+00104, 00171+00105 and 00171+00106. Completed historical edges remain; no native-peer/Feature blocker
was added. Generated children/waiting and next handoffs agree; earlier native/declaration/Feature/metadata statuses,
orders and blockers plus first Board TASK-00107 remain unchanged. At that checkpoint TICKET-00052 had no TASK children and no new
EPIC/TICKET IDs were allocated. Five-family ownership is 2 + 1 + 1 + 1, not source/native/supplier/consumer qualification;
product source/dependencies are unchanged and no product build was run.

TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning as 12 TASKs,
not implementation/qualification or a second testing programme. It reuses supplier tests and only closes demonstrated
gaps, with no new per-framework suites/runner/quota. Next: `/skill:next` for Board-based selection. All six audit TICKETs and
six typing TICKETs are TASK-planned, not implemented/qualified; EPIC-00015's added Agent conversion and actual Common
supplier planning/qualification remain separate unfinished handoffs. No earlier Wayfinder phase applies to this
standalone destination. Board-based execution selection follows selected remaining planning with explicit checkout
choice; this approval starts no implementation.
