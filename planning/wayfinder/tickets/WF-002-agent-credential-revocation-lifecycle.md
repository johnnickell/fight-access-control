# Establish Agent credential and revocation lifecycle

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Map:** [Agent HMAC authentication and direct authority](../agent-hmac-authentication-map.md)
**Depends on:** [Define framework-neutral HMAC Agent authentication](WF-001-hmac-agent-authentication-boundary.md)

## Planned replacement amendment — 2026-09-27

John ratified [EPIC-00009 decision D1](../../epics/00009-EPIC.md#d1--breaking-replacement-and-adr-0004wf-002-amendment)
and its [ADR 0004 amendment](../../adr/0004-agent-hmac-credential-lifecycle.md): separately replace raw-return
provision/rotation with operation-correlated protected delivery and safe results, without a legacy raw-return escape
path. The pre-1.0 breaking minor classification is accepted. John subsequently selected `v0.5.0` as the target;
release authorization remains separate.

For that future replacement, this explicitly amends the exclusion of operational recovery and the issuance-only
raw-return/materialization boundary. One active credential, immediate rotation, terminal revocation and the
nonce/current-authority fence remain. John also ratified
[EPIC-00009 decision D2](../../epics/00009-EPIC.md#d2--transactional-authorization-and-protected-sink-integration)
on 2026-09-27: mandatory same-connection transactional authorization fences and protected-sink ordering,
deduplication and receipt verification; unsupported integrations fail closed. Already-admitted calls may stage inert
bytes, not authorize stale activation or use. John ratified
[EPIC-00009 decision D3](../../epics/00009-EPIC.md#d3--post-commit-result-behavior) on 2026-09-27: only replacement
recoverable provision and rotation return confirmed committed metadata with a typed, sanitized publication warning
when post-commit publication fails. This explicitly amends the failure/rethrow rule below for that boundary alone;
pre-commit failure and indeterminate commit remain distinct, with same-key and authorized scheduler recovery even
if both publishers fail. Issuance is not delivery, enrollment activation or launch permission; each needs its own
confirmed outcome and current authorization. D3's failure/restart tests gate implementation acceptance. Revocation
and other operations are unchanged.

John ratified [EPIC-00009 decision D4](../../epics/00009-EPIC.md#d4--bounded-operation-and-integration-policy) with an
amendment on 2026-09-27: finite documented defaults and optional validated overrides, not mandatory manual
configuration or additional human approval for routine operation/recovery. Capacity limits give new work clear
retryable rejection/deferral while preserving authorized status and existing-operation recovery. Cleanup retains
anti-duplication and stale-delivery evidence; concrete values and implementation choices move to requirement/design
work before implementation acceptance. John confirmed the complete EPIC destination and boundaries on 2026-09-27;
the separately approved requirement decomposition is now TICKET-00012–00014 under that EPIC. TASK planning remains
separate.
The historical resolution below and this closed record are preserved; no completed work is reopened and no runtime
change, decomposition or implementation is authorized by these planning decisions.

## Unreleased implementation checkpoint

[TASK-00046](../../tasks/00046-TASK.md) implements the amended provisioning and same-key resolution path with safe
metadata, not raw return. See the [provisioning contract](../../../docs/agent-provisioning-operations.md). The closed
resolution below remains historical; downstream lifecycle/delivery, replacement rotation and real consumer
qualification are still required before adoption. No map decision is reopened by this implementation.

## Question

How does the Agent aggregate own one active HMAC credential and its immediate revocation or replacement while raw
secret material, encryption, persistence, and delivery remain consumer-owned?

## Must decide

- The Agent lifecycle states and stable identifiers required to distinguish provisioned, active, rotated, and
  revoked authentication authority without retaining a grace credential.
- The portable credential-generation and cipher/key-access ports, including where raw secrets are permitted and
  how command/event/read contracts remain secret-free.
- The atomic ordering and durable evidence for provision, rotation, and revocation; failed work must rethrow after
  the failure event and no success event may precede commit.
- The authentication race behavior when a signed request overlaps rotation or revocation.

## Resolution boundary

This ticket may establish aggregate invariants and Application orchestration. It must not prescribe persistence
records, a key vault, a secret-delivery channel, an operational recovery flow, or multiple simultaneously valid
credentials.

## Resolution

An Agent transitions from `PROVISIONED` to `ACTIVE` only through an explicit credential-provision command. While
active, it owns exactly one HMAC credential identity and one consumer-encrypted shared-secret envelope. The public
credential ID selects that authority during authentication. Rotation is an explicit command, not scheduled expiry:
it replaces the credential immediately, advances the Agent credential revision, and leaves the Agent `ACTIVE`.
`REVOKED` is terminal; recovering access requires provisioning a new Agent identity. There is never a grace
credential or multiple concurrently valid credentials.

Application owns an `AgentCredentialGenerator` and consumer-owned cipher/key-access ports. Raw shared-secret
material may exist only while the Application provisions, rotates, or verifies an HMAC credential. Provision and
rotation return it exactly once through a non-serializable result after commit. Commands, events, views, audit
evidence, and failure events contain only safe Agent and credential identity/lifecycle data.

`ProvisionAgentCredential`, `RotateAgentCredential`, and `RevokeAgentCredential` each make one atomic Unit of Work:
they mutate the Agent, write secret-free durable audit evidence, commit once, then emit their success event.
Rotation includes the expected current credential ID, so a stale request fails closed. Authentication linearizes at
the atomic nonce-consumption step, which also confirms that the same credential ID and revision remain active.
Thus an authentication finalized before a lifecycle commit may succeed; one finalized after rotation or revocation
fails closed. See [ADR 0004](../../adr/0004-agent-hmac-credential-lifecycle.md).
