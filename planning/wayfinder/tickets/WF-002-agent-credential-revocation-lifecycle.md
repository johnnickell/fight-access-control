# Establish Agent credential and revocation lifecycle

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Map:** [Agent HMAC authentication and direct authority](../agent-hmac-authentication-map.md)
**Depends on:** [Define framework-neutral HMAC Agent authentication](WF-001-hmac-agent-authentication-boundary.md)

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
