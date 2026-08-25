# Establish Agent credential and revocation lifecycle

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
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

Write this only when the decision is closed. Link the epic, PRD, or implementation-ticket handoff created by the
resolved map where relevant.
