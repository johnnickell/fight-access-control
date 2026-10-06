# Confirm Feature package and consumer integration scope

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Permission-based feature flags](../permission-based-feature-flags-map.md)
**Depends on:** [WF-017](WF-017-feature-availability-and-preview.md), [WF-018](WF-018-feature-preview-permission-binding.md), [WF-019](WF-019-missing-feature-records.md), [WF-020](WF-020-feature-naming-and-management.md), [WF-021](WF-021-feature-evaluation-freshness.md)

## Question

Should the first EPIC deliver the framework-neutral AccessControl capability and its consumer integration contract,
with actual application UI, adapters, and CMS migration planned separately?

## Must decide

- Package versus consumer ownership, especially the name-only Attribute, registry input, current-code discovery, and
  runtime enforcement. A declaration alone does not enforce access.
- Whether a named consumer adoption/migration is required for this EPIC, or follows it separately.
- Disposition of remaining map fog: explicit exclusions versus requirement/design details, without reopening the
  five accepted behavioral decisions or inventing additional runtime mechanisms.

## Required evidence

- The inspected [project profile](../../agents/project-profile.md), [README](../../../README.md), and
  [CONTEXT](../../../CONTEXT.md) restrict production code to Domain/Application. Consumers own persistence,
  transport, framework composition, UI, runtime, and entry-point authorization.
- [Composer](../../../composer.json) autoloads only Domain/Application production namespaces and has no production
  framework dependency. [Deptrac](../../../deptrac.yaml) allows Application to depend on Domain, public Common
  contracts, and PHP internals. Native PHP Attribute metadata can fit this boundary without a Symfony listener or
  a new Adapter namespace; its exact namespace and API require normal design review.
- At inspected AccessControl base `ea1e316c0cfa3499e73b213f538257bfd1ee3888`, no production Attribute/registry
  implementation was found by source-path inspection. Do not claim an existing reusable Feature scanner.
- The public package boundary above excludes framework-specific listeners and filesystem scanners. A portable
  name-only Attribute can be package-owned while discovery/enforcement remains consumer-owned; exact target and
  composition behavior requires an explicit package decision rather than copying a consumer integration.
- Private consumer-source inspection informed the discussion. Repository/revision/file provenance and implementation
  analysis remain in ignored local planning evidence, not in this public record; no consumer qualification is claimed.
- This is source and planning-scope inspection, not a product build, consumer qualification, or independent implementation
  review. No existing implementation TASK scope is changed.

## Approved initial EPIC scope

### AccessControl delivers

- Feature model, identity/name/status values, mandatory Permission-ID binding, repository contracts, and accepted
  invariant behavior from WF-017 through WF-021.
- Framework-neutral evaluation with fresh Feature reads and the consumer's existing authenticated User/Agent snapshot;
  support the accepted anonymous ON behavior without creating an anonymous authenticated principal or authenticating
  inside the Feature evaluator.
- Framework-neutral management operations and safe read results sufficient for the consumer UI: create/list/read,
  change status/Permission, and guarded deletion, including lightweight expected-state updates. Exact messages and
  signatures belong to requirement/design work, not new endpoints or a CLI.
- A package-owned, framework-neutral name-only PHP Attribute and a small registry representation/contract for
  discovered Attribute names plus explicit programmatic references. Neither performs framework interception or
  filesystem discovery by itself.
- Package-owned automatic provisioning and deletion policy, with narrow consumer contracts for current-reference
  discovery and storage. Provisioning resolves the supplied default Permission name, creates missing records OFF,
  and preserves existing choices. Consumers do not reimplement these business rules in deployment scripts.
- Tests of package behavior, consumer-bindable contract scenarios where needed, and integration guidance stating
  scanner completeness, reference integrity, fresh reads, and authorization obligations. Passing package tests does
  not establish that a consumer wired its Attribute enforcement or deletion scan correctly.

### Consuming applications deliver separately

- Persistence adapters and schema migrations, real current-code scanning, registry composition, and deployment hooks.
- Framework/runtime enforcement of declared checks and registered programmatic checks across the consumer's actual
  entry points, using the package evaluator rather than duplicating its policy.
- Management UI, Permission selector, three-state control, warning/confirmation, backend authorization, HTTP response
  mapping, diagnostics, and consumer end-to-end qualification. No production UI belongs in AccessControl.
- Environment-specific composition and DEFAULT_FEATURE_PERMISSION configuration. Each environment uses its
  configured repository/catalog; no new package environment/tenant dimension or rollout matrix in this EPIC.
- Any Fight CMS adoption, Role-to-Permission conversion, existing-data migration, or other named consumer rollout.
  CMS remains the reference implementation, not an implicitly authorized migration target.

### Keep for requirement/design work

- Exact public namespaces/signatures, Attribute target and composition rules, registry discovery interface, anonymous
  caller representation, operation/error/result types, query pagination, and emitted events.
- Provisioning batch atomicity/retry behavior, database uniqueness/reference enforcement, revision mechanics, and
  adapter proof for fresh reads. Preserve the approved lightweight boundaries: no distributed deployment fencing.
- Complete scenario-to-test traceability, any affected public schema metadata, compatibility impact, and version choice.
  Release, publication, deployment, and consumer qualification remain separately authorized.

These are downstream specifications within the agreed behavior, not silently approved optional product features.
If design uncovers a genuine product choice or a conflict with WF-017–WF-021, return it for a decision rather than
inventing an exception. No percentage rollout engine, shared cache, in-flight cancellation, management CLI, standalone
public management API, or historical name-reservation system is added.

## Resolution boundary

Confirm one package EPIC destination and the integration handoff. After approval, summarize the accepted decisions
in that EPIC and close this map; TICKET/TASK decomposition is separate. Do not implement or publish either repository.

## Resolution

Closed. John approved the package-only boundary: AccessControl owns the framework-neutral Feature capability,
Attribute/registry contracts, policy, tests, and integration guidance. Consumer persistence, scanning, enforcement,
UI, configuration, and deployment wiring remain separate, as does actual Fight CMS adoption/migration.

[EPIC-00010 — Permission-Based Feature Flags](../../epics/00010-EPIC.md) is the approved handoff. Remaining technical
items above belong to requirement/design work; consumer migration and release/publication require separate authority.
No implementation TASKs or production code are created by this approval.
