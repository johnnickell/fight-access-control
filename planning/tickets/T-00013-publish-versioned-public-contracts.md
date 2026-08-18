---
id: T-00013
title: Publish versioned public contracts
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00005
  - T-00009
  - T-00012
branch: feature/t-00013-publish-versioned-public-contracts
---

# Publish versioned public contracts

## Outcome

Frontend and framework consumers can generate one strict type surface from a versioned OpenAPI contract and a
versioned realtime-schema union without exposing private domain data.

## Acceptance criteria

- [ ] OpenAPI and realtime JSON Schema assets have explicit versions and validate in the package build.
- [ ] Public event names, safe envelope transformations, and allowlisted metadata are defined without FQCNs, secrets, administrative reasons, or arbitrary domain fields.
- [ ] Invalidation events use authoritative-refetch semantics where complete state is unsafe to publish.
- [ ] Strict generated TypeScript output is committed and stale output is rejected by tests.
- [ ] Contract and conformance tests cover declared fields, versioning, safe envelopes, and consumer-facing failures.

## Exclusions

No HTTP routes, websocket or Mercure transport, React client, code generator hosting, or framework starter integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
