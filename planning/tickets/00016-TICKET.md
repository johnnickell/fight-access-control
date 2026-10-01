---
id: TICKET-00016
epic: EPIC-00010
title: Evaluate Feature availability for Users and Agents
status: ready-for-agent
---

# Evaluate Feature availability for Users and Agents

## Problem and Outcome

Consumers need one framework-neutral Feature decision that supports human and machine preview audiences, observes
current Feature configuration, and never confuses feature availability with authorization to perform an action.
Unknown Features and invalid Permission references must not accidentally expose unfinished behavior.

John approved this requirement area in the three-TICKET decomposition of
[EPIC-00010](../epics/00010-EPIC.md), then approved a single implementation slice,
[TASK-00064](../tasks/00064-TASK.md), and the interface direction below. This records the approved scope, not
deployment authorization. TASK-00062 is complete and the evaluator is implemented but awaiting independent
acceptance; no partial Feature release is qualified.

## Use Cases

| Actor and trigger | Commands | Queries / operations | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A consumer checks a Feature for an authenticated User | N/A: evaluation does not mutate | Evaluate the named Feature using the existing User authority snapshot | N/A: no domain mutation or success/failure command event | OFF denies; PREVIEW requires the bound Permission in Role-derived authority; ON removes only the Feature restriction. |
| A consumer checks a Feature for an authenticated Agent | N/A | The same evaluation with existing Agent authority | N/A | Equivalent status decisions using direct Permissions; no Agent Roles or protected-Permission grant bypass. |
| A consumer checks an anonymously accessible use case | N/A | Evaluate without an authenticated principal | N/A | A valid ON Feature adds no restriction; OFF and PREVIEW deny. Underlying action authorization remains the consumer's responsibility. |
| The same request checks again after Feature settings change | N/A | Read current stored status and Permission binding again | N/A | The next check uses current Feature settings, not the earlier Feature row/result; existing principal-snapshot lifetime is unchanged. |
| A long-running worker begins another job or explicitly checks during an admitted job | N/A | Evaluate again through fresh storage reads | N/A | No Feature settings survive between checks/jobs. A job with no additional check can finish even after OFF is selected. |
| A lookup finds no Feature or a broken Permission reference | N/A | Evaluate with a distinguishable configuration failure/diagnostic | N/A | Unknown name errors; broken references deny without state mutation, automatic creation, default substitution, or implicit ON. |

Read-only diagnostic reporting is not a domain state change. Consumers may record safe internal diagnostics; this
TICKET does not create an audit-event pipeline or translate failures into HTTP responses.

## Availability and Permission Requirements

For a known Feature with a valid referenced Permission:

| Stored status | User or Agent with the bound Permission | User or Agent without it | Anonymous |
| --- | --- | --- | --- |
| OFF (`off`) | Unavailable | Unavailable | Unavailable |
| PREVIEW (`preview`) | Available under existing tier/assignment rules | Unavailable | Unavailable |
| ON (`on`) | No Feature restriction | No Feature restriction | No Feature restriction |

- Availability never grants business/action authorization, skips authentication required by that action, or grants
  Feature management authority. There is no Role-name or super-admin bypass and no hard-coded testing Permission.
- Users obtain Permission authority through existing Roles; Agents use direct Permissions. A Feature may bind any
  existing Permission, but a protected Permission remains human-only under the unchanged assignment policy.
- Resolve the Feature's stored Permission ID as that identity. Existing name-based helpers must not silently rebind
  the Feature to a new Permission that reused a deleted Permission's name. Tests must cover equal-name/different-ID
  cases against the existing principal snapshot contract, not just string-name matching.
- A missing referenced Permission is invalid configuration at every status, including ON and OFF. Prevent availability
  with a distinguishable broken-binding error; do not mutate the Feature, recreate the Permission, or consult the
  provisioning default. Consumers retain an internal diagnostic rather than silently masking the configuration fault.
- Unknown Feature names raise a distinguishable unknown-Feature error rather than imply ON or ordinary unavailability.
  Invalid FeatureName input uses shared validation from TICKET-00015. John approved booleans for ordinary availability,
  distinguishable errors for unknown Features and broken bindings, and separate operational failures that never become
  an availability result. Concrete exception names remain bounded implementation design.
- A consumer may conceal broken-reference details with a 404 like OFF. This does not turn database outages into 404
  or change unknown-name semantics. Package operations return no HTTP response and own no transport error envelope.

## Freshness and Side-effect Boundary

- Each explicit evaluation reads current stored Feature status and Permission binding. A second check in one request
  is a fresh evaluation; no request/job-local Feature row or availability result is reused as the decision source.
- Consumer repository contracts must preserve this behavior, including avoiding stale ORM identity-map results.
  No shared Feature cache, cache invalidation mechanism, or cross-request retained settings is required.
- Reuse the existing authenticated User/Agent snapshot; do not reauthenticate on each check or construct a fake
  anonymous authenticated principal. Feature freshness does not promise immediate grant/revocation refresh within
  the existing principal lifetime and introduces no process-lifetime principal cache.
- OFF affects the next explicit check after the change is visible to the authoritative read. Evaluation neither
  schedules extra checks nor cancels admitted work, undoes prior effects, or promises atomic coordination with an
  action's later execution. Document this limit rather than introducing cancellation or distributed locking.
- Evaluation performs no aggregate mutation, Unit of Work commit, provisioning, success-event publication, grant,
  or automatic repair. Runtime failure must never fall back to available.

## Dependencies and Consumer Integration

Depends on [TICKET-00015](00015-TICKET.md)'s Feature values, repository/identity contract, and registration boundary.
[TICKET-00017](00017-TICKET.md) supplies configuration changes and reference guards used in cross-use-case scenarios;
this does not make management implementation a prerequisite for specifying the evaluator with controlled repositories.

Consumers compose authenticated authority or anonymous input and enforce the decision at their actual entry points.
A package-owned Attribute is only metadata. Scanning, framework interception, ordinary authorization, transport
mapping, internal diagnostics, and worker authentication scopes remain consumer responsibilities. Supported checks
must be discoverable through Attributes or explicit registration; unregistered dynamic checks are unsupported.

John approved accepting an existing AuthenticatedUserPrincipal or AuthenticatedAgentPrincipal, or null for anonymous,
with a validated FeatureName. Both existing snapshots expose Permission IDs; match those IDs and leave the shared
AuthenticatedAuthority interface and name-based helpers unchanged. No authentication refactor or extra principal
representation is needed. TASK-00064 owns concrete evaluator/error names, fresh-read adapter obligations, tests,
consumer-bindable scenarios, and any affected public compatibility/schema contract. Genuine product ambiguities
still return for approval rather than silently changing the existing principal lifecycle.

TASK-00064 depends only on completed [TASK-00062](../tasks/00062-TASK.md)'s Feature model/repository, transitively
using [TASK-00061](../tasks/00061-TASK.md)'s name/declaration contract. It does not wait for
[TASK-00063](../tasks/00063-TASK.md)'s preparation validation or TICKET-00017 management: controlled repositories
support evaluator proof now. Actual management-to-evaluation composition belongs to
[TASK-00066](../tasks/00066-TASK.md); retirement/reintroduction/validation/evaluation composition and final lifecycle
traceability belong to [TASK-00067](../tasks/00067-TASK.md). Both depend downstream on evaluation, not the reverse.
No circular TASK dependency or supported partial Feature release is implied.

## Exclusions

No authentication redesign, direct User grants, Agent Roles, generic policy engine, shared cache, cancellation,
framework middleware/listener, production persistence adapter, consumer runtime qualification, CMS migration, or
release/publication. Actual action authorization remains outside Feature availability.

## Acceptance Evidence

- [ ] Package tests prove the status/principal matrix, Role-derived User and direct Agent authority, shared/different
      preview cohorts, protected human-only preview, and absence of privileged Role/name bypasses.
- [ ] Unknown names, invalid names, missing Permission references at every status, equal-name/different-ID Permission
      histories, and operational read failures never grant availability or provision/repair records. Ordinary boolean
      results, unknown-Feature errors, broken-binding errors, and infrastructure failures remain distinguishable.
- [ ] The evaluator accepts existing User/Agent snapshots or null, matching stored Permission IDs without modifying
      AuthenticatedAuthority, existing name-based helper behavior, or principal serialization.
- [ ] Repeated checks observe status and Permission changes within one request and across worker jobs; unchanged
      principal snapshots retain their documented grant/revocation lifetime without additional authentication calls.
- [ ] Controlled scenarios distinguish an admitted job finishing without another check from a later explicit check
      denying after OFF; tests do not claim automatic interruption or transactional action admission.
- [ ] Evaluation has no writes, commits, command events, grants, or default-Permission fallback. Consumer guidance
      preserves separate action authorization and explains diagnostic/transport distinctions.
- [ ] Consumer-bindable freshness and authority scenarios identify real adapter/runtime proof still required; package
      doubles do not establish actual ORM freshness, framework enforcement, or consumer authorization correctness.
- [ ] Implementation passes `./bin/planning-check` and the full `./bin/build`, including exact production statement
      coverage. Planning validation is not evidence that the runtime capability exists.

## Decision Links and Progress

Implements [WF-017](../wayfinder/tickets/WF-017-feature-availability-and-preview.md),
[WF-018](../wayfinder/tickets/WF-018-feature-preview-permission-binding.md),
[WF-019](../wayfinder/tickets/WF-019-missing-feature-records.md),
[WF-021](../wayfinder/tickets/WF-021-feature-evaluation-freshness.md), and
[WF-022](../wayfinder/tickets/WF-022-feature-package-integration-scope.md). Existing principal/assignment boundaries
remain as described in [CONTEXT](../../CONTEXT.md) and
[ADR 0010](../adr/0010-permission-eligibility-and-caller-authorization.md).
John approved TASK-00064 as one complete evaluator slice, with existing User/Agent snapshots or null as input,
ID-based preview checks, and boolean availability plus distinguishable configuration/operational errors. At the
planning checkpoint it awaited TASK-00062; implementation and local verification have since completed, while
independent acceptance remains pending. TICKET-00017 now has approved TASK-00065–00067, completing EPIC
decomposition. John subsequently authorized TASK-00064 in the clean main checkout on
`feature/task-00064-feature-evaluation` from `develop` `b2fc4ce`. Its read-only evaluator and
[consumer guide](../../docs/feature-availability.md) now implement the approved boolean/missing/broken/operational
contract and consumer-bindable fresh-read scenarios. At the initial builder checkpoint, focused and complete gates
passed 74 tests / 415 assertions and 1812 tests / 35068 assertions, exact 6369/6369 owned statements. The first
independent review requested documentation and in-flight completion proof corrections; the revised full gate passes
1812 tests / 35070 assertions, exact 6369/6369 statements. Independent re-review and behavioral QA remain
outstanding; this does not qualify actual ORM freshness, framework enforcement, consumer authorization,
management composition, a partial release or deployment. TASK-00065–00067 retain their separate scope.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 64 | [TASK-00064](../tasks/00064-TASK.md) | Evaluate Feature availability with fresh, identity-safe checks | ready-for-agent |
