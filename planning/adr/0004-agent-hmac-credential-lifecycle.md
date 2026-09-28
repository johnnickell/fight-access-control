# ADR 0004: Agent HMAC Credential Lifecycle

- Status: accepted
- Date: 2026-08-24

## Planned replacement amendment — 2026-09-27

John ratified [EPIC-00009 decision D1](../epics/00009-EPIC.md#d1--breaking-replacement-and-adr-0004wf-002-amendment):
replace raw-return provision/rotation APIs in a separately versioned pre-1.0 breaking minor release, without a legacy
raw-return escape path. The replacement requires scoped operation identity and authorized protected delivery,
returns safe metadata, and permits bounded package-owned recovery through a public sensitive sink invocation.
Consumers continue to own persistence, sink implementations and keys. One active credential, immediate rotation,
terminal revocation and authentication's current-authority fence remain unchanged.

This amends the future raw-once return, authentication-only unwrap and consumer-only delivery boundary below;
it does not claim that recovery is implemented. John also ratified
[EPIC-00009 decision D2](../epics/00009-EPIC.md#d2--transactional-authorization-and-protected-sink-integration)
on 2026-09-27: transactional authorization shares the package-owned transaction and authority fences; protected sinks
must support immutable ordered staging, stable delivery-ID deduplication and exact verifiable receipts. Unsupported
integrations fail closed. Post-admission calls may stage inert bytes but cannot authorize stale activation or use.
John ratified [EPIC-00009 decision D3](../epics/00009-EPIC.md#d3--post-commit-result-behavior) on 2026-09-27:
only replacement recoverable provision and rotation return confirmed committed metadata with a typed, sanitized
publication warning on post-commit publication failure. Pre-commit failures and indeterminate commits stay distinct;
same-key and authorized scheduler recovery survive even both publishers failing. Issuance does not imply credential
delivery, enrollment activation or launch permission; each needs its own confirmed outcome and current authorization.
The failure/restart tests in D3 are required before implementation acceptance. Revocation and other operations retain
their existing behavior.

John ratified [EPIC-00009 decision D4](../epics/00009-EPIC.md#d4--bounded-operation-and-integration-policy) with an
amendment on 2026-09-27: documented finite defaults and optional validated overrides, without mandatory manual
configuration or additional human approval for routine credential operation/recovery. Capacity exhaustion rejects or
defers new work with a clear retryable outcome while preserving authorized status and existing-operation recovery.
Cleanup retains evidence preventing duplicate issuance or stale delivery. Concrete values and implementation choices
belong in requirement/design work before implementation acceptance. John confirmed the complete EPIC destination
and boundaries on 2026-09-27, then separately approved its TICKET-00012–00014 requirement decomposition.
TASK planning and implementation remain separate operations.
The historical decision below describes the existing contract until the separately authorized replacement is
implemented and released. John subsequently selected `v0.5.0` as the target release; release authorization remains separate.

## Unreleased provisioning implementation — TASK-00046

TASK-00046 implements the provision/service-retry portion of the amendment. Its
[public contract](../../docs/agent-provisioning-operations.md) replaces raw-return provision with scoped correlation,
transactional authorization, prepared protected delivery and safe confirmed/indeterminate results. Existing legacy
rotation/revocation retain their behavior; newly recoverable Agents reject those unfenced paths until downstream
lifecycle work exists. Full delivery, rotation replacement and consumer qualification remain outstanding. This
intermediate implementation is not a supported deployable composition or release approval.

## Decision

An Agent has an explicit credential lifecycle: `PROVISIONED` to `ACTIVE` through provision, `ACTIVE` to `ACTIVE`
through rotation, and any eligible state to terminal `REVOKED` through revocation. These are explicit commands;
credential expiry and grace credentials are unsupported. Rotation requires the expected current credential ID and
immediately replaces its predecessor. Restoring access after revocation requires a new Agent identity.

The active authority has a public credential ID and one consumer-encrypted HMAC shared-secret envelope. Application
ports generate and access that secret. Raw material is returned once after a successful provision or rotation commit,
and is unwrapped only while verifying an HMAC request. It never appears in serializable messages, views, audit
evidence, success events, or failure events.

Each lifecycle command mutates the Agent and writes secret-free audit evidence in one Unit of Work, commits, then
emits its success event. Authentication atomically consumes its nonce only after confirming that its credential ID
and revision remain current. The first committed authentication or lifecycle operation wins; later work fails closed.

## Consequences

Consumers retain ownership of cipher implementation, keys, secret delivery, persistence, transport, and runtime
composition. The package gets a precise, testable authority boundary without a production Adapter layer or an
overlapping active credential during rotation.
