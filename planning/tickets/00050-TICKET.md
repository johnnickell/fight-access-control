---
id: TICKET-00050
epic: EPIC-00018
title: Enforce Feature admission across five frameworks
status: ready-for-agent
---

# Enforce Feature admission across five frameworks

## Problem and Outcome

Under [EPIC-00018](../epics/00018-EPIC.md), Feature metadata and FeatureAvailability currently leave HTTP enforcement
to consumers. Supply thin optional native integrations that enforce effective action requirements before protected
validation/action work across Slim, Yii 3, Symfony, Laravel and CodeIgniter 4, independently of Permission middleware.
Availability remains a restriction, never authorization for the underlying action.

## Use Cases and Contracts

| Actor and trigger | Observable outcome |
| --- | --- |
| Integrator selects Feature admission only | Deliberate optional registration without requiring Permission middleware or unrelated framework infrastructure. |
| Native routing selects an action/explicitly bound handler | Enforce TICKET-00049's effective trusted Feature requirements, preserving class/method/inherited/route conjunction. |
| Existing User/Agent or anonymous caller reaches a valid Feature gate | Reuse OFF/PREVIEW/ON evaluation and fresh authoritative binding reads; no privileged-name bypass or new grant. |
| OFF or unmet PREVIEW denies a gated action | Generic HTTP 404 without Feature names/configuration; no protected validation/action work. |
| Metadata, binding, context or provider/storage fails | Safe configuration/operational fault, not disabled Feature, implicit public access or successful admission. |
| Another request/subrequest uses the same container | Correct fresh target/context lifetime without stale principal, headers or accidental caller propagation. |

Admission creates no business checking Command/Query, mutation evidence or Domain Event. Existing authentication's
necessary session/nonce effects remain distinct; do not replay authentication merely to apply another gate.

## Requirements and Validation

- Reuse one framework-neutral Feature requirement/evaluation meaning with thin optional adapters: PSR-15 for Slim and
  Yii 3 where honest (separate native qualification), Symfony native subscriber/listener, Laravel middleware and
  CodeIgniter 4 before-filter/response short-circuit. Keep capability-first placement and Adapter → Application → Domain.
- Consume TICKET-00049's effective declarations for the selected action; explicit trusted route bindings cover closures
  and other supported forms. No effective Feature declaration means no Feature restriction, not permission to bypass
  action authorization. Do not copy a second Attribute merger or trust client headers/arguments as requirements.
- Call existing FeatureAvailability semantics with trusted User/Agent snapshot or deliberate anonymous null. OFF denies
  everyone; PREVIEW requires the stored bound Permission ID; ON passes even anonymously but grants no action authority.
  Preserve actual identity/binding validation at all statuses and authoritative fresh settings on each explicit check.
- Unknown/malformed/conflicting authenticated context or failed providers cannot become anonymous fallback. Preserve
  User Role-derived versus Agent direct Permission identity and normal principal lifetime. Fresh Feature state does
  not refresh grants inside a captured principal or authorize a live/background continuation.
- Map only ordinary OFF/unmet PREVIEW refusal to generic 404. Missing definitions, broken/mismatched bindings, malformed
  declarations and provider/storage/native faults remain safe configuration/operational errors. Never parse arbitrary
  messages/codes/causes, expose Feature/Permission/configuration details or silently admit on failure.
- Compose Common's actual selected safe-error/native boundary with one redacted reporting owner and safe fallback,
  not a competing reporter/renderer. Preserve supported header/negotiation behavior and explicit non-JSON/downstream
  coverage limits; no unsafe debug/legacy fallback or MCP/OAuth envelope replacement.
- Enforce before protected validation/action/use-case work. Prove real matched route/action association, supported
  class/method/invokable/closure/inherited forms and request/subrequest/reset behavior. Preserve qualified early
  boundaries while documenting eager/bootstrap construction and Symfony/Laravel lifecycle limits rather than claiming
  universal pre-construction denial. Unsupported protected resolution cannot silently skip the gate.
- Feature admission runs without Permission middleware. When both are selected, both must pass without overwriting the
  other's requirements/context, duplicate authentication/nonce use or making Feature ON an authorization bypass.
  Cross-capability reconciliation belongs to TICKET-00052; this ticket still owns its own complete native behavior proof.
- Keep installed routes, authentication, scope/policy, persistence, scanner/code identity and bootstrap consumer-owned.
  No evaluation-time scan, auto-provisioning, binding repair, transaction, business mutation or cancellation/recheck
  scheduler is introduced. Document actual supported/minimum framework/Common composition and remaining adoption gaps.

## Dependencies and Existing Work

TICKET-00049's approved [TASK-00170](../tasks/00170-TASK.md) supplies the complete Feature declaration/discovery
path and shared pure selected-action/ancestry output. Its sibling TASK-00171 portable Permission declarations and
TICKET-00051 runtime Permission integration are not prerequisites for independent Feature admission.
Coordinate actual shared native infrastructure with TICKET-00032/TASK-00103–00106, without duplicating Permission
adapter implementation or silently expanding existing TASK scope/order/blockers. The approved plan below assigns
true affected native supplier edges, not a blanket wait for every framework/architecture/Common plan. TICKET-00052's matrix can
begin early, but does not defer this family's focused tests/docs/gate/independent acceptance.

Preserve EPIC-00010 evaluation semantics and actual EPIC-00015/00016 placement/typing ownership. No new Common API is
currently demonstrated as missing; verify actual tagged/sufficient-minimum and ordinary lanes, naming any genuine
affected gap rather than inventing upstream IDs, guessed native hooks or a local error framework. Apply ADR 0011
without aliases/old behavior modes/backfill; Common retains its own compatibility policy. Obtain applicable complete
private criteria before implementation/review without disclosure; explicitly choose the execution checkout.

## Acceptance Evidence

- [ ] All five native framework journeys support deliberate Feature-only enablement and effective class/method/route/
  inherited requirements, with real denial before protected validation/action and accurate construction limits.
- [ ] User/Agent/anonymous OFF/PREVIEW/ON matrices, fresh state changes, name/ID mismatch, broken binding and unknown
  context preserve existing evaluation and authority boundaries; no new grants or fallback identity.
- [ ] Generic Feature 404 differs from configuration/storage/provider faults, with safe output, one reporting owner,
  fault/header/negotiation/fallback proof and explicit non-JSON/consumer coverage limitations.
- [ ] Sequential/nested/subrequests and exception paths preserve current target/context and correct short-circuit,
  without stale state, protected bypass, duplicate authentication or replayed Agent nonce.
- [ ] Optional dependencies, supported tagged/minimum/ordinary compositions, current callers/docs, focused checks,
  full gate/exact coverage, complete evidence and independent Review/nonvisual behavioral QA qualify every slice.

## Exclusions

Feature evaluator/model policy redesign, new authentication/grants/authorization, provisioning/scanner infrastructure,
consumer bootstrap/persistence/schema/data operations, UI, arbitrary PHP-call interception, MCP/CLI/worker/live checks,
Common/sibling repair, compatibility/migration/backfill, release/version selection and deployment. Native/package
fixtures do not qualify actual consumer scanner, auth/storage/sinks/proxies, installed bootstrap or production runtime.

## Approved TASK Plan and Native Ownership

John approved four complete ordinary ticketed feature TASKs covering all five frameworks as 2 + 1 + 1 + 1:

| TASK | Order | Complete delivered outcome | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00172](../tasks/00172-TASK.md) | 503 | Shared inward Feature admission/context and thin PSR-15 enforcement, separately qualified with real Slim and Yii 3 journeys. | TASK-00170, completed TASK-00103 |
| [TASK-00173](../tasks/00173-TASK.md) | 504 | Native Symfony Feature admission, trusted target association, early lifecycle and safe exception/response composition. | TASK-00172, completed TASK-00104 |
| [TASK-00174](../tasks/00174-TASK.md) | 504 | Native Laravel middleware and existing Common safe native handler, with real kernel/router/lifetime/report/render proof. | TASK-00172 |
| [TASK-00175](../tasks/00175-TASK.md) | 504 | Native CI4 before-filter/response short-circuit consuming TASK-00106's planned Common-backed native safe error bridge. | TASK-00172, TASK-00106 |

All four are ready-for-agent because scope is settled; unfinished actual supplier edges still hold execution.
Orders 503/504 prefer the first complete shared path, then equal native peers without choosing a relative framework
priority. Completed TASK-00103/00104 edges remain history. No earlier scope/order/blocker changes or new dependency
on TASK-00171/TICKET-00051, Permission activation, all architecture/typing/audit/Common work or another native peer.

TASK-00172 owns the shared inward admission/context meaning once and the first complete native paths, not five copies
of FeatureAvailability or TASK-00170's hierarchy merger. Preserve concrete trusted User/Agent or deliberate anonymous
context, actual Feature/Permission-ID/binding checks at every status, fresh settings and no authority from ON. Invalid
context/provider/metadata/storage/binding faults never become anonymous or ordinary unavailability. Only ordinary
OFF/unmet PREVIEW maps to generic 404; missing definitions/broken bindings remain safe faults, distinct from earlier
optional consumer concealment advice. Keep Domain exceptions Domain-owned and actual typed translation outward.

Every native slice owns its own real selected-action/route association, supported controller/class/method/invokable/
closure/inherited forms, trusted explicit bindings, pre-validation/action enforcement and honest construction/eager-
bootstrap limits. Qualify reused containers, nested/subrequests, exceptions and response state without stale context,
implicit parent authority, forged request-attribute grants or replayed Agent nonces. Feature-only works with Permission
middleware absent; cooperating checks retain both restrictions and one appropriate request authentication/snapshot.
No scanning/provisioning/transaction/audit/checking business message or new grant is introduced merely to perform a gate.

Safe native composition reuses Common's actual policy/presentation with one redacted reporting owner, qualified
headers/challenges, finite public output/fallback and explicit non-JSON/downstream coverage. TASK-00174 has no
TASK-00105 blocker: Common already supplies SafeLaravelExceptionHandler; native dependency/fixture/wiring overlap is
coordination, not an automatic wait or permission to redo base Permission work. TASK-00175 does consume TASK-00106's
actual planned thin error bridge: inspected Common has CI4 response helpers, not an equivalent safe native exception
owner. A helper or before-filter catch alone does not qualify downstream interception. Supplier reuse activates no
Permission middleware/filter; generic error code gains no Feature business knowledge or competing renderer/logger.

Each slice retains current callers/docs/examples/Unreleased updates, optional dependency isolation, sufficient tagged
minimum/ordinary/core-only composition, focused behavior/static checks, canonical full gate/exact production coverage,
complete evidence and independent Review/nonvisual behavioral QA. TICKET-00052 owns final cross-capability/native
reconciliation, not deferred Feature proof. Preserve native MCP/OAuth envelopes, actual authentication/record scope and
consumer scanner/auth/storage/crypto/sink/proxy/bootstrap/runtime qualification gaps. Apply ADR 0011 without aliases/
legacy modes/history readers/migration/backfill; Common keeps its own compatibility policy. No new combined release
obligation/version, consumer/sibling repair, schema/data operation or deployment follows. Obtain complete applicable
authorized private criteria before implementation/review without disclosure; choose the execution checkout explicitly.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 503 | [TASK-00172](../tasks/00172-TASK.md) | Enforce Feature admission through PSR-15 | in-progress |
| 504 | [TASK-00173](../tasks/00173-TASK.md) | Enforce Symfony Feature admission | ready-for-agent |
| 504 | [TASK-00174](../tasks/00174-TASK.md) | Enforce Laravel Feature admission | ready-for-agent |
| 504 | [TASK-00175](../tasks/00175-TASK.md) | Enforce CodeIgniter 4 Feature admission | ready-for-agent |
## Decisions and Progress

John approved this requirement and subsequently the complete four-TASK plan now TASK-00172–00175, orders 503/504/504/504.
The manifest covers all five native families as 2 + 1 + 1 + 1 without duplicate policy/error-bridge ownership. These are
planning allocations, not source/native/supplier/consumer qualification or independent implementation acceptance.
Readiness means settled scope, not supplier completion. Existing TASK scope/orders/blockers and first Board selection
TASK-00107 remain unchanged.

Planning only: no source/dependency/Common/consumer change, product build, new checkout/branch/worktree,
commit/publication, release/version selection or deployment follows.

Planning generation/read-only validation passes (244 records, 118 active), alongside documentation links and diff/
new-record whitespace checks. Metadata confirms four ordinary ticketed features, orders 503/504/504/504 and exact
direct supplier counts 2/2/1/2, retaining completed edges and no TASK-00105/Laravel wait. Generated children/waiting and
next handoffs agree; existing TASK-00103–00106 orders/blockers and first Board TASK-00107 are unchanged. Five-family
allocation is 2 + 1 + 1 + 1; at that checkpoint TICKET-00051/00052 still had no TASK children. These are planning structure/ownership
checks, not source/native/supplier/consumer qualification; product source/dependencies are unchanged and no product
build was run.

TICKET-00051's subsequent four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/
qualified. It consumes existing declaration/native suppliers, not TASK-00172–00175 or Feature activation; current
Feature scope/orders/blockers remain unchanged.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning as 12 TASKs,
not implementation/qualification or a second testing programme. Next: `/skill:next` for Board-based selection. TICKET-00049 is TASK-planned as two declaration features;
audit's six TICKETs and typing's six accepted TICKETs are also TASK-planned, not implemented/qualified. Added EPIC-00015
Agent conversion and actual Common supplier planning/qualification remain separate unfinished handoffs. No earlier
Wayfinder phase applies to this standalone destination. Finish selected remaining planning before Board-based
execution selection; checkout choice remains explicit and no workflow starts from this approval.
