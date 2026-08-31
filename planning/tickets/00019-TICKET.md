---
id: T-00019
prd: PRD-00002
title: Provision an Agent with one HMAC credential
status: done
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

- [x] Provisioning creates an `ACTIVE` Agent with exactly one credential ID at its initial credential revision.
- [x] The raw shared secret is available only in the successful non-serializable result after the atomic commit.
- [x] Commands, views, audit evidence, events, and failure events contain no raw shared secret or encrypted envelope.
- [x] A failed mutation, audit write, or commit rethrows unchanged and publishes no success event.
- [x] Tests prove aggregate invariants, serialization safety, audit-and-commit ordering, and exact coverage.

## Verification

- Focused Agent aggregate and provisioning Application tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- Added the extensible Agent aggregate, stable Agent and credential identifiers, `ACTIVE` lifecycle authority at
  initial revision zero, a consumer-encrypted HMAC shared-secret envelope, and the Agent repository contract.
- Added the synchronous provisioning service with generator and cipher ports, a deliberately non-serializable raw-
  secret result, typed secret-free Agent audit evidence, and post-commit `AgentProvisioned` success evidence.
- Agent provisioning failures rethrow unchanged, emit no success event, and publish only generic secret-free
  `AgentProvisioningFailed` evidence. Tests prove mutation, audit, commit, serialization, and post-commit ordering.
- Post-rebase `./bin/planning-check` passed with 27 records and 7 active records. Post-rebase `./bin/build` passed
  with 521 tests, 3,719 assertions, and exact 3,872/3,872 statement coverage; PHPCS, PHPStan, architecture,
  package-boundary, Rector, documentation, and production-autoload checks also passed.
