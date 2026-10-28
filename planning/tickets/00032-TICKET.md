---
id: TICKET-00032
epic: EPIC-00014
title: Protect native HTTP entry points across all five frameworks
status: in-progress
---

# Protect native HTTP entry points across all five frameworks

## Problem and Outcome

Consumers otherwise repeat request/route Permission checks and risk incorrect lifetime, validation ordering or
unsafe error handling in each framework. Provide thin optional native integrations around the existing inward
authority/Permission tooling, with Common-compatible safe errors, without owning consumer authentication policy,
application grants/scope or bootstrap.

Cover all five named families in [EPIC-00014](../epics/00014-EPIC.md)'s combined release: Slim, Yii 3, Symfony,
Laravel and CodeIgniter 4. Reuse shared PSR-15 where honest; native hooks translate real lifecycle differences, not
five copies of permission policy. [WF-035](../wayfinder/tickets/WF-035-framework-permission-adapter-ownership.md)
approves this narrow `src/Adapter` boundary and supersedes ADR 0001's earlier no-Adapter restriction within that
scope only; Domain/Application dependency rules and DDD organization remain intact.

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Integrator explicitly enables a supported framework integration | N/A; optional composition | Register only selected integration and dependencies; no route, issuer, grants, unrelated adapter or global handler installed implicitly. |
| Framework selects a protected HTTP target | No new checking message | Inspect valid declared requirements and trusted request context; deny before protected input validation/dispatch. |
| An authenticated User or Agent reaches admission | Existing appropriate authority provider, not a new token/session issuer | Select exactly one trusted authority and check declared Permissions without conflating human Roles and Agent direct grants. |
| Same process/container serves another request or native subrequest | No new business message | Correct current context/lifetime; no stale singleton principal or accidental caller identity propagation. |
| A deliberate auth/permission failure or operational fault reaches HTTP | Common's selected safe failure/presentation boundary | Explicit classification, required headers, private diagnostics, safe fallback and one reporting owner; no raw exception output. |
| Admitted endpoint later exposes live/background work | Subsequent runtime owner invokes TICKET-00028 where applicable | Middleware admission is not a lifetime grant and does not manage the later stream/job. |

Adapters add no business Command, Query or Domain event simply to check a Permission. Initial authentication may
retain its existing nonce/session side effects through the appropriate provider; validation/business handling and
its Commands/Queries execute only after admission. Consumer use cases retain their own target-specific checks.

## Framework and Structure Requirements

| Family | Required integration direction |
| --- | --- |
| Slim | Shared PSR-15 middleware with consumer route/context composition; portable core must not depend on Slim routing. |
| Yii 3 | Reuse PSR-15 where actual route/context semantics fit; qualify native composition separately from Slim. |
| Symfony | Native subscriber/listener at a qualified post-routing admission point; preserve supported native authorization/attribute composition. |
| Laravel | Native middleware with explicit application registration and safe native error composition. |
| CodeIgniter 4 | Native before filter with correct response short-circuit and request/context lifetime. |

- Use capability-first `Adapter/<Capability>/<Framework-or-Standard>/...`; service-container extension points belong
  under `Adapter/ServiceContainer/<Framework>/...`. Follow Common's current ADR 0023, not legacy aliases or
  framework-first/DDD aggregate buckets. No persistence or unrelated infrastructure is approved.
- Keep `Adapter → Application → Domain`, optional/isolated dependencies, deliberate registration and one inward
  permission-check implementation. Define mirrored Adapter tests, autoloading and architecture/coverage wiring in
  the later approved implementation; this planning does not change executable configuration.
- Protect declaration/route/context integrity. Missing/malformed/ambiguous protected declarations or caller-supplied
  authority cannot become implicit public access. Any explicitly public/authenticated-only policy must be deliberate
  and distinguished from broken configuration; do not copy Agent OS's application-specific public allowlist.
- Validate actual supported callback/controller forms and request/subrequest behavior. Native route metadata comes
  from trusted framework composition, not client headers/arguments masquerading as requirements.
- Deny before protected validation/dispatch. Specify/prove any pre-controller-construction promise against actual
  framework order: Symfony `kernel.controller` follows controller resolution and is not sufficient by itself.
- No Agent role-name/administrator bypass, OAuth-scope conversion, default credentials or copied Permission arrays
  as authority. Reuse existing User/Agent semantics rather than implementing one framework's identity in Domain.

## Safe-Error Requirements

- Remain compatible with/reuse Common's selected safe Application categories and HTTP presentation integration.
  Permission adapters signal deliberate failures rather than copying exception messages into bespoke responses.
- Establish explicit type-based classification/translation for known outcomes; arbitrary exception code/message/
  cause, storage faults or standalone consumer RuntimeExceptions do not automatically become 401/403. A projector
  does not reclassify a type that the selected Common policy considers unknown.
- Public output uses fixed safe defaults or deliberately public-safe projection. Validate required challenge/header
  metadata, safe fallback, finite encoded data and consumer-selected scheme; do not invent universal Bearer policy.
- Supply exactly one redaction-aware reporting owner covering the selected native path before raw exceptions reach
  other loggers/formatters. Avoid competing native/PSR renderers or double-reporting; sink/projection/render failure
  must follow the actual selected safe contract. Unsafe legacy handlers are not a fallback.
- Preserve MCP JSON-RPC/Tool exact-class failure/concealment and OAuth-specific outcomes; ordinary HTTP/JSend
  integration must not wrap or replace those protocols. Domain exceptions remain Domain-owned.
- Agent OS migration/consumer bootstrap or response-contract changes are not performed here; document explicit
  adoption differences instead of claiming its custom failure mapper is a drop-in Common handler replacement.

## Dependencies and External Gates

- Reuse existing trusted authentication and `SecurityContext`/Permission contracts. Ordinary native HTTP admission
  does not depend on MCP-specific TICKET-00025/00026/00027 or on new live protocol implementations.
- [TICKET-00028](00028-TICKET.md) supplies checks for actual later protected disclosure/continuation, not a forced
  dependency for every short request. Runtime owners must call it when that behavior is exposed.
- Verify actual Common safe-error APIs/minimums and each framework's supported version/lifecycle. The baseline is
  already in tagged Common v1.4.0, but current AccessControl `^1.3` does not guarantee those declarations exist.
- Native package fixtures qualify owned integration. Actual application bootstrap, cryptography/persistence,
  policy/scope, installed routes/sinks/proxies and deployment remain separate consumer evidence.
- All five families remain in the single selected release; no relative framework priority was approved. Downstream
  TASK slicing is not constrained to one TASK/PR or one independently duplicated policy per framework.

## Acceptance Evidence

- [ ] All five actual framework-native journeys show permitted dispatch and denied/malformed/context-fault paths
  before protected validation/work; shared PSR-15 reuse is separately qualified with Slim and Yii 3 composition.
- [ ] Real inward authority checks preserve User/Agent distinctions and correct request/subrequest/reset behavior
  across multiple requests in one process/container; no stale principal, client-controlled grant or role bypass.
- [ ] Native order/controller-form proof supports only the documented pre-construction guarantees, and alternate
  direct use-case paths retain their own consumer protection.
- [ ] Known refusals, unknown/operational faults, required challenges/headers, projection/sink/renderer failure and
  negotiation use Common-compatible safe output and one redacted reporting attempt without private messages/data.
- [ ] No unsafe legacy fallback, competing renderer, MCP/OAuth envelope replacement, implicit unrelated service/
  framework activation or consumer bootstrap takeover occurs.
- [ ] Supported framework/Common minimums, optional dependency isolation, Composer/architecture wiring, mirrored
  tests, exact statement coverage, documentation and canonical gate qualify owned code before acceptance.
- [ ] Later independent Review/behavioral QA distinguishes package/native fixtures from real consumer adoption;
  documentation records remaining actual-runtime/sink/security qualification rather than claiming universal safety.

## Exclusions

Application scope/grant/authentication policy, keys/token issuers, consumer bootstrap/routes, persistence adapters,
new protocol endpoints, duplicate error handling, actual Agent OS/starter source changes or deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 103 | [TASK-00103](../tasks/00103-TASK.md) | Protect Slim and Yii 3 requests through shared PSR-15 admission | done |
| 104 | [TASK-00104](../tasks/00104-TASK.md) | Protect Symfony requests through native admission | done |
| 104 | [TASK-00105](../tasks/00105-TASK.md) | Protect Laravel requests through native middleware | ready-for-agent |
| 104 | [TASK-00106](../tasks/00106-TASK.md) | Protect CodeIgniter 4 requests through native before filters | ready-for-agent |
## Decisions and Progress

Current acceptance: TASK-00104 has independent Review ACCEPT and QA PASS on candidate
`fb25934087f931527325c3d66aa49e2b83a944eb`. Publication and final delivery were pending at closeout;
current result: `.runs/handoffs/TASK-00104/land.md`. TASK-00105 and TASK-00106 remain unfinished, so this
TICKET stays open. The Work and next-stage checkpoints below are historical.

John approved item 8, explicitly invoked To Tasks, then approved four complete adapter journeys. The full accepted
plan is written, covering all five frameworks without duplicated permission policy:

| Approved slice | TASK | Direct local blockers |
| --- | --- | --- |
| A — Shared PSR-15 with separately qualified Slim and Yii 3 journeys | [TASK-00103](../tasks/00103-TASK.md) | None |
| B — Native Symfony admission | [TASK-00104](../tasks/00104-TASK.md) | TASK-00103 |
| C — Native Laravel middleware | [TASK-00105](../tasks/00105-TASK.md) | TASK-00103 |
| D — Native CodeIgniter 4 before filters | [TASK-00106](../tasks/00106-TASK.md) | TASK-00103 |

All are ready-for-agent in the decision-complete sense: A has no local blocker; B–D wait on its inward declaration/
authority/failure contracts and initial Adapter/gate wiring, not runtime Slim/Yii dependencies. A has order 103 and
B–D share order 104 as independent peers, with stable IDs only as display ties; no relative product priority among
native framework peers is selected. Ticket-owned features omit kind. No inherited MCP/future-runtime needs-info hold
or TASK-00088 prerequisite is introduced for ordinary requests. Tagged Common v1.4.0 supplies the safe-error baseline;
actual sufficient minima, native lifecycles and optional dependency qualification remain each feature's acceptance.

Each TASK owns complete actual authority/native request/response/error evidence, tests/docs, supported-minimum proof,
exact coverage/full gate and independent Review/behavioral QA. A includes real Slim AND Yii 3 qualification plus first
Composer/autoload/architecture/mirrored Adapter/default-suite wiring, not an interfaces-only prefactor. Native peers
reuse inward policy and Common-safe presentation without competing renderers/loggers. Symfony controller-event and
Laravel controller-middleware construction limits are explicit; CI4's native response helper alone is not a safe
exception/reporting owner. JSON/non-JSON delegated paths are not automatic HTML sanitization.

All selected map decisions, single EPIC, eight TICKET decompositions and TASK plans are now accepted/written as
TASK-00084–00106 (23 complete feature TASKs). Planning is complete, not implementation or parent done. The Board owns
execution selection. Keep map Closed, combined release/all five frameworks, opt-in capabilities, polling independence,
external qualification/tag/minimum gates and unassigned version. No source/dependency/config/grant change,
implementation, commit, publication, adoption or release is authorized; choose checkout before separate execution.
Actual consumer bootstrap/authentication/persistence/crypto/scope/sinks/runtime qualification remains separate.

Current delivery: TASK-00104 intake verified TASK-00103 PR #143 MERGED into `develop` at
`d4fda9b41fff0dde922c7dae569ee71805d0e358` on 2026-10-28T12:10:37Z. Published head
`87e3d640aaf882c58dddfe2effbefa5c6fe05cf5` is integrated at that selected base; its marker is retired.
No TASK-00103 review/publication/merge action remains. Its acceptance and pending-at-closeout wording below are historical.

Current Work: TASK-00104 implements the optional native Symfony subscriber using the accepted inward contracts,
without Slim/Yii runtime dependence or duplicated policy. See [Symfony composition](../../docs/symfony-permission-admission.md)
for trusted route metadata, request/subrequest lifetimes, six controller forms, native IsGranted/Fight Validation order,
Common-only safe exception/reporting/fallback/negotiation and tagged minima. Controlled native/minimum/core-only fixtures
are not consumer bootstrap/firewall/persistence/crypto/scope/sink/deployment qualification. Final package-gate evidence
belongs to the TASK and Work handoff; independent Review/QA are pending. Native peers/this TICKET remain unfinished.

Historical acceptance: TASK-00103 has independent Review ACCEPT and nonvisual QA PASS on candidate `2807785e332187a36299c08ce3826e9fed585729`; publication and final delivery were pending at closeout, with current results in `.runs/handoffs/TASK-00103/land.md`. TASK-00104–00106 remain unfinished, so this TICKET stays open. The following Work checkpoint is historical.

The planning handoff above is historical. TASK-00103 now implements shared inward declaration/authority/admission
contracts and the optional PSR-15 adapter with separately qualified native Slim/Yii 3 journeys. See the
[HTTP composition guide](../../docs/http-permission-admission.md) for supported route forms, provider/request lifetimes,
Common-owned safe failure presentation and actual tagged minimums. TASK-00103's final native-bound gate passes
2597 tests / 48148 assertions and exact 7703/7703 statements. The regression-first native Agent fixture-binding
correction has focused 29-test / 445-assertion proof and that fresh full-gate/content bridge; production contracts
are unchanged. Independent Review/QA remain pending and this TICKET is not done. Native peer TASKs still wait on TASK-00103's accepted inward contracts/wiring, not
Slim/Yii runtime. The combined release/all five frameworks/unassigned version are unchanged.

The preceding TASK-00103 review-next wording is superseded by its accepted/integrated checkpoint above.
Next for active TASK-00104: independent `/skill:review TASK-00104` after its verified committed Work handoff.
