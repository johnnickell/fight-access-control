---
id: T-00019
prd: PRD-00002
title: Provision an Agent with one HMAC credential
status: ready-for-agent
blocked_by:
---

# Provision an Agent with one HMAC credential

## Outcome

A maintainer can provision a new Agent and receive its first HMAC shared secret exactly once after the Agent,
secret-free audit evidence, and authority state commit successfully. The durable Agent state contains only safe
identity, lifecycle, credential identity and revision, and a consumer-encrypted secret envelope.

## Scope

- In scope: Agent identity and lifecycle, a single active credential, credential generation and cipher ports,
  non-serializable provision result, durable audit evidence, post-commit success event, and in-memory test support.
- Out of scope: credential rotation or revocation, signed-request authentication, direct Permission assignment,
  production persistence, key management, secret delivery, HTTP, and consumer runtime composition.

## Acceptance Criteria

- [ ] Provisioning creates an `ACTIVE` Agent with exactly one credential ID at its initial credential revision.
- [ ] The raw shared secret is available only in the successful non-serializable result after the atomic commit.
- [ ] Commands, views, audit evidence, events, and failure events contain no raw shared secret or encrypted envelope.
- [ ] A failed mutation, audit write, or commit rethrows unchanged and publishes no success event.
- [ ] Tests prove aggregate invariants, serialization safety, audit-and-commit ordering, and exact coverage.

## Verification

- Focused Agent aggregate and provisioning Application tests
- `./bin/planning-check`
- `./bin/build`
