# v0.5.0 contract and release overview

This document describes the selected **v0.5.0** package contract. At the 2026-10-06 preparation checkpoint, the
implementation is integrated in `develop`; release review, integration into `main`, exact-commit certification,
human signing and publication remain separate gates. A dated changelog or this guide is not a publication receipt.
See the [changelog](../CHANGELOG.md#050---2026-10-06) for the detailed changes.

## Requirements and compatibility

- PHP **8.5 or later** and `johnnickell/fight-common` **^1.3** are required. Common's released MCP contracts supply
  metadata, registry, neutral availability and synchronous command dispatch; consumers own their runtime wiring.
- This is a **breaking pre-v1 minor**, not a patch to v0.4.0. Only the current API and persisted contract are
  supported under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). Update consumer implementations
  and callers directly; no historical readers, legacy Agent mode, old-signature stubs, conversion/backfill or
  mixed-version compatibility layer is supplied. This policy grants no permission to reset consumer data.
- Production remains `Domain <- Application`. Consumers supply persistence, shared transactions, authentication
  and entry-point authorization, cryptography, sinks/providers, scanning, UI, scheduling and deployment.
- OpenAPI components are an opt-in distribution, not package-owned endpoints or a complete document. Recompose
  consumer documents and regenerate clients as needed for the changed public schemas.

## Integrated capabilities and required consumer work

| Area | v0.5.0 contract | Consumer obligations / owning guide |
| --- | --- | --- |
| Agent credential operations | Provision/rotation retain a scoped operation key and original issuance, return safe metadata rather than raw credentials, and distinguish pre-commit failure, uncertain commit and confirmed issuance with a sanitized publication warning. Atomic predecessor retirement fences delivery. | Implement current Agent/operation repositories, transactional authorization and protected destination composition. [Integration guide](agent-integration.md), [provision](agent-provisioning-operations.md), [rotation](agent-rotation-operations.md), [retirement](agent-credential-retirement.md). |
| Protected delivery and recovery | Separate claim/admission/outcome transactions, outside-transaction sink invocation, exact receipt binding, bounded discovery/restart recovery, rewrapping, expiry and replay-safe cleanup. | Share authority, lifecycle, key, slot-order and transaction fences; qualify the real sink, keys and worker. [Delivery](agent-credential-delivery.md), [recovery](agent-delivery-recovery.md), [maintenance](agent-delivery-maintenance.md). |
| Current-contract admission and restore | One canonical marker `2`; mandatory persisted cohort/capability contract and independent nullable `reconciledGeneration`. Missing/mismatched readiness denies unsafe effects. Original keys, receipts, tombstones and monotonic order survive cleanup. | Implement `getOperationContract()` on both repositories, fence every writer and supply trusted evidence outside the restorable dataset. [Cohorts](agent-operation-cohorts.md), [canonical contract](agent-canonical-upgrades.md), [restoration](agent-restoration-safety.md). |
| Human credential delivery and expiry | Explicit due/claim/outcome inputs; bounded expired-work discovery and direct invitation/reset delivery expiry, plus full email authority/reservation expiry even after terminal delivery or account-state changes. | All three grant repositories implement `findExpired(at, limit)`; email grants persist the required User reservation revision and couple full-state CAS with User expiry. Run bounded expired cleanup before due work. [Delivery](credential-delivery.md), [expiry](credential-expiry.md). |
| Permission-based Features | Strict names and declarations, complete candidate/current discovery, atomic OFF provisioning, read-only preparation, fresh OFF/PREVIEW/ON evaluation, revision-fenced management and current-reference retirement. | Implement Feature persistence and shared Permission-reference guards, complete scanning, management authorization/confirmation and runtime enforcement. Feature availability never authorizes the underlying action. [Declarations](feature-references.md), [provisioning](feature-provisioning.md), [preparation](feature-preparation.md), [evaluation](feature-availability.md), [management](feature-management.md), [retirement](feature-retirement.md). |
| Agent names and minimal profiles | Name-only last-committed-wins update preserves credentials/Permissions; repository samples time after writer admission. Fresh ACTIVE profile reads expose only `{agent_id, name}`. | Implement mandatory `AgentRepository::rename(id, name, now)` and authorize generic reads/writes. Fix the two managed `ADMIN_SAFE` profile Permission IDs once in consumer seed data; no automatic grants. [Rename](agent-name-updates.md), [profiles](agent-profile.md). |
| Agent-protected MCP Tools | Registry-derived conjunctive Permission requirements and request-scoped availability; self-service read/update derive the target solely from the principal. Update acknowledges normalized input only after successful synchronous void dispatch. | Use Common ^1.3, fresh request composition, separate read/update grants and `SynchronousCommandBus`. Protect alternate entry paths and sanitize failures. No endpoint or OAuth mapping is supplied. [Authorization](agent-mcp-authorization.md), [profile Tools](agent-profile-tools.md). |
| Public OpenAPI values | New safe delivery/discovery/maintenance/expiry/Feature/name components; invitation status matches the seven current states, removing `failed`/`confirmed`. | Rebuild consumer documents; the enum correction is breaking under ADR 0007. The obsolete `ConfirmPasswordResetDelivery` component/Command is removed; workers still publish `PasswordResetDeliveryConfirmed` after committed outcomes. [Composition](openapi-composition.md). |

`ManagedPolicyPlanner` also requires an Agent repository; the older optional composition is removed. Current
protected-tier eligibility and authoritative membership checks remain. All current Agent lifecycle writes require
operation correlation; there is no legacy-mode escape hatch.

## Package acceptance versus consumer qualification

The canonical records mark the integrated implementation complete: Agent operations and current-contract cleanup
(TASK-00046–00059 / TASK-00068), OpenAPI corrections (TASK-00060), Features (TASK-00061–00067), Agent rename/profile
(TASK-00069–00071), MCP authorization (TASK-00035) and human expiry (TASK-00072). TASK-00055's old legacy contract is
superseded by ADR 0011 and TASK-00068, not a v0.5.0 compatibility guarantee. The accepted human-expiry slice satisfies
the explicitly required package prerequisite for this release.

The [Agent evidence inventory](agent-operation-evidence.md), [Feature evidence inventory](feature-evidence.md) and
[expiry evidence](credential-expiry.md#matrix-and-evidence-boundary) distinguish modeled
package behavior and retained independent acceptance from unexecuted real-consumer qualification. Prior TASK
reviews do not independently accept the release documentation or certify the final release merge.

Before consumer adoption, qualify actual database isolation/CAS/shared transactions, every authority writer, real
keys/sinks and restart/restore tools, scanner completeness, runtime security and activation/use. Issuance is not
delivery; delivery is not enrollment activation or continuing permission to use a credential. Package tests and
publication cannot establish these consumer outcomes. No consumer dependency update, data reset, deployment or
external planning completion is authorized by this release preparation.

## Delivery boundary

The release follows `release/0.5.0` from `develop` into `main` through an independently accepted merge-commit PR.
The repository-owned `./bin/release certify 0.5.0` must then pass on the **exact clean release merge commit** before
the human creates its signed annotated `v0.5.0` tag. An earlier candidate build or equivalent tree is not final
certification. Tag push, GitHub release publication and matching Packagist source/dist projection are distinct
verified effects. Merge-back to `develop` and authorized owned-resource cleanup complete delivery; no automatic
planning archive or production deployment follows.
