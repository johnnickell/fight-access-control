---
id: TICKET-00041
epic: EPIC-00016
title: Align presentation and integration contracts
status: ready-for-agent
---

# Align presentation and integration contracts

## Problem and Outcome

Correct handler contracts are insufficient if exported schemas, protocol bindings or examples still describe
nullable results, erase types or expose private exceptions. Under [EPIC-00016](../epics/00016-EPIC.md), make all
owned presentation/integration boundaries agree with the corrected library and prove safe protocol composition.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Integrator composes ordinary HTTP admission and errors | Existing use-case failures; no new Command/Event | Intentional Application failures map safely through Common's opt-in error path, not private diagnostics. |
| Caller invokes an existing protected MCP capability | Existing profile Tools, Queries/Commands and family bindings | Precise input/result contracts and explicit protocol failures retain admission and scope behavior. |
| Consumer builds an OpenAPI document | Current public message, safe result and synchronous payload components | Generated schemas match canonical fields, types, optional states and serialization. |
| Maintainer updates consumer composition | Existing current examples and exported PHP contracts | Nullable-result/catch/dispatch changes and current obligations are explicit without consumer repository edits. |

## Requirements and Ownership

- Account for every Adapter and all presentation, registry/catalog, metadata and protocol-binding declarations,
  even where currently placed in Application. Include shipped OpenAPI metadata, owned schemas and current examples;
  do not omit code merely because EPIC-00015 plans to move it.
- Correct native/static inputs, outputs, callbacks, arrays/collections and provider/catalog binding contracts using
  actual underlying use-case types. Align Tool schemas and actual results without repeated Action-level result
  assertions, a copied upstream workflow or direct-handler bypass.
- Verify relevant qualified Common safe HTTP composition: ResourceNotFound becomes a safe 404; deliberate conflict,
  caller-precondition and rule rejection remain distinct; internal identity/result violations and unknown faults
  remain safe server errors. Type-based intent, never message text/integer codes/causes, selects classification.
- Preserve fixed/allowlisted public projections and diagnostic privacy, including messages, causes, stack arguments,
  structured field errors and secret-bearing data. Honor the existing optional-framework admission and safe-error
  composition limits; this is not an automatic safe migration for arbitrary consumer error handlers.
- Preserve separate MCP/OAuth failure contracts, current explicit registrations and protocol envelopes. HTTP
  inheritance matching does not imply MCP exact-class mapping. Preserve concealment, scope, authority freshness,
  request lifetimes and current continuation protections; typing never confers a permission grant.
- Match shipped public payload components to canonical toArray/current safe result contracts, including typed
  collection elements, optional states, IDs/enums/timestamps and request-only secrets. Follow ADR 0008: do not
  introduce package-owned generic failure schemas, transport routes, cookie/header policy or diagnostic output.
- Audit owned nullable-result callers/catches and update examples, integration guides and Unreleased breakage
  notes directly. Document required external caller/adapter changes and unexecuted consumer qualification, without
  editing consumer Actions or supplying a compatibility mode. Do not change released historical documentation.
- TICKET-00039/00040 own use-case failure/result semantics; this ticket proves presentation/binding/schema agreement
  with them. TICKET-00038 owns generic dispatch guarantees. Each source-changing slice includes its directly affected
  tests/docs; this ticket is not permission to leave earlier slices with knowingly incorrect public contracts.

## Dependencies and Existing Work

Coordinate with [TICKET-00033](00033-TICKET.md) for MCP presentation placement and TICKET-00034/TASK-00110 for canonical
Common adoption. Do not duplicate the relocation or expand the five-framework feature scope from TICKET-00032.
Test actual current and accepted target boundaries, with explicit TASK suppliers where needed; no global wait for
all Common/architecture work is implied.

Actual qualified/tagged Common failure and adapter contracts plus sufficient-minimum/ordinary dependency lanes
gate affected composition. Native framework requirements remain optional and separately qualified. Package fixture
proof is not actual consumer storage, crypto, sink/bootstrap or deployment qualification. No Common/consumer edits,
automatic safe-handler installation or new endpoint is authorized.

## Acceptance Evidence

- [ ] Every owned Adapter and presentation/binding/schema declaration has a coverage disposition, actual underlying
  input/result/failure contract and correction or justified unchanged behavior, regardless of current namespace.
- [ ] Real HTTP composition proves intended 404/conflict/precondition/rule/server behavior and safe fallback without
  disclosing private exception diagnostics or bypassing required authentication metadata.
- [ ] Existing MCP/OAuth envelopes and explicit mappings remain correct; real owned invocation retains permission,
  concealment, fresh scope and request/continuation contracts without repeated caller result-type assertions.
- [ ] Generated OpenAPI integration tests prove current payload/collection/optional-state agreement and secret
  separation; no route/status/header/error-schema policy is added by implication.
- [ ] Current examples/docs and external nullable-result/catch obligations match the new contracts with breaking
  classification, no compatibility mode and explicit unexecuted consumer obligations.
- [ ] Actual supplier minima/composition and each slice's focused tests, canonical full gate/exact coverage, local
  evidence and independent Review/applicable QA support the package claims, not consumer deployment readiness.

## Exclusions

Consumer Action refactoring, consumer error-renderer replacement, new endpoints/framework support, package generic
error schemas, Common implementation, production persistence, compatibility/migration and release/deployment.
No new business Command/Query/Event, permission grant or atomicity guarantee is introduced.

## Approved TASK Plan and Coverage

John approved the complete four-TASK plan, now written as kind chore with orders 325–328:

| TASK | Primary code/metadata files | Outcome | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00146](../tasks/00146-TASK.md) | 2 | Ordinary HTTP contracts and category-correct safe failures through current PSR/native composition. | None demonstrated. |
| [TASK-00147](../tasks/00147-TASK.md) | 6 | Protected Agent profile Tools, exact-class MCP failures, actual schemas/output and typed bus consumption. | TASK-00128, TASK-00131, TASK-00141. |
| [TASK-00148](../tasks/00148-TASK.md) | 6 | Resource/Skill binding, descriptor/revision, admission and protocol contracts. | None demonstrated. |
| [TASK-00149](../tasks/00149-TASK.md) | 9 | Public payload schemas/bootstrap and FeatureFlag declarative metadata. | TASK-00133. |

At the approved-plan checkpoint, all four were ready-for-agent because scope was settled; TASK-00147/00149
waited on the listed unfinished local suppliers. Current status and executable blockers derive from the owning
TASK records and Board.
TASK-00147 needs actual Query inference, required-profile absence and Agent-update failure contracts, not mocked or
asserted replacements. TASK-00149 consumes actual explicit credential-status absence representation, not a guessed
wrapper or consumer HTTP response. Preference order is not a serial dependency and no mutual edges are demonstrated.
Existing TASK scope/order/blockers and Board priority remain unchanged.

Primary manifests allocate 2 Adapter + 13 Application presentation + 8 OpenAPI files = all 23 current owned files,
as 2 + 6 + 6 + 9 without overlap/omission. Refresh actual declarations/components and additions/moves/removals at
execution; counts are planning allocation, not completed source review, runtime/type/supplier/consumer proof or a
claim all required schema components already exist. Each boundary needs a corrected or justified unchanged disposition.

TASK-00146 owns ordinary HTTP examples and applicable typed-failure/redaction guidance, not a new renderer/logger.
TASK-00147 owns Tool catalog/Attribute/availability, both profile Tools and exact-class failure mapping; TASK-00148
owns Resource/Skill bindings/catalogs/availability. TASK-00149 owns all seven component anchors, bootstrap and
FeatureFlag, with an exhaustive declaration-to-component/disposition map and generated OpenAPI 3.1 tests, not counts
or PHP-text assertions. Current examples and breakage obligations follow each owner; central README/Unreleased or
shared protocol fixtures are updated once by the first affected owner, not deferred or duplicated.

Actual current supported Common/native APIs suffice for independent HTTP/Resource/Skill investigation; no new API
gap is identified there. Reverify tagged/sufficient-minimum and ordinary composition at execution. TASK-00147 inherits
TASK-00128's actual supplier hold through a real local edge, without inventing an external ID. TASK-00132 retains
Common ResultSet runtime validation; unchanged page wire metadata/schema tests neither qualify that promise nor
inherit it as a blanket blocker. Native UoW mixed plus matching local @var for known owned callback results remains
explicitly sufficient, with no generic UoW API/task or redundant result check; this is not bus inference or external validation.

Follow actual canonical locations after TASK-00108 and actual APIs after TASK-00110 without repeating relocation/
adoption or requiring every architecture task. TASK-00105/00106 and unfinished MCP families retain their feature
scope; include actual added boundaries when present without implementing or waiting for every future integration.
TICKET-00039/00040 retain use-case semantics and directly necessary earlier caller/schema/doc fixes. These four tasks
reconcile/prove presentation agreement, not excuse earlier knowingly broken public contracts. TICKET-00042 retains
integrated final reconciliation after its early inventory/enforcement foundations.

Each slice owns relevant actual protocol/schema/native tests, regressions for demonstrated defects, current callers/
examples, supported minimum/ordinary lanes, canonical full gate/exact owned coverage, complete evidence and independent
Review/nonvisual behavioral QA. Preserve Tool neutral pre-execution containment versus Resource/Skill operational
propagation; ordinary HTTP safe categories do not automatically qualify MCP exact-class mapping or OAuth responses.
No consumer/Common edits, compatibility mode, new business policy/grant/endpoint, release or deployment follows.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 325 | [TASK-00146](../tasks/00146-TASK.md) | Qualify ordinary HTTP contracts and safe failures | ready-for-agent |
| 326 | [TASK-00147](../tasks/00147-TASK.md) | Align protected Agent profile Tool contracts | ready-for-agent |
| 327 | [TASK-00148](../tasks/00148-TASK.md) | Align Resource and Skill binding contracts | ready-for-agent |
| 328 | [TASK-00149](../tasks/00149-TASK.md) | Align public payload schemas and declarative metadata | done |
## Decisions and Progress

John approved this requirement in the six-TICKET split, then the complete four-TASK plan now written as
TASK-00146–00149. At that planning checkpoint all were ready-for-agent; TASK-00147 waited on
TASK-00128/00131/00141 and TASK-00149 on TASK-00133. Current statuses and remaining blockers derive from the
owning records and Board. TASK-00146/00148 have no demonstrated local blockers. Readiness is not
runtime/type/supplier/consumer qualification.

Planning only: no implementation, source/dependency/sibling/consumer mutation, product build, new branch/worktree/
checkout, commit/publication, release or independent acceptance is claimed. Obtain applicable complete authorized
private criteria before implementation/review without disclosure, and resolve the execution checkout separately.

At this four-TASK checkpoint, planning generation/read-only validation passed (213 records, 87 active), alongside
documentation links and diff/record whitespace checks. Resolved manifests allocate 23/23 current owned files without overlap/omission as 2 + 6 +
6 + 9, with exact approved blocker metadata. This is planning coverage only, not source audit, runtime/type/supplier/
consumer qualification; no product build was run and the Board still selects TASK-00107 first.

TICKET-00042's three-TASK plan is now approved/written as TASK-00150–00152, with independent early foundations and
final integrated enforcement consuming these four slices' actual acceptance rather than replacing their proof.

TICKET-00045's separate administration audit plan is now approved/written as TASK-00153–00157.
TICKET-00046's separate four-TASK Feature/policy audit plan is now approved/written as TASK-00158–00161.
TICKET-00047's four-TASK audit plan is now approved/written as TASK-00162–00165.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00015's added
conversion requirements/TASK plan and actual Common supplier work/qualification remain unfinished handoffs.
Finish selected planning before implementation selection; the Board remains execution authority with TASK-00107 first.
