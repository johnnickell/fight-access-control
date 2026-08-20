---
id: T-00013
title: Publish versioned public contracts and editable React client
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00005
  - T-00009
  - T-00012
branch: feature/t-00013-publish-versioned-public-contracts
---

# Publish versioned public contracts and editable React client

## Outcome

Frontend and framework consumers receive one strict type surface plus a complete editable React authentication
client from versioned OpenAPI and realtime-schema contracts without exposing private domain data.

## Acceptance criteria

- [ ] OpenAPI and realtime JSON Schema assets have explicit versions and validate in the package build.
- [ ] Public event names, safe envelope transformations, and allowlisted metadata are defined without FQCNs, secrets, administrative reasons, or arbitrary domain fields.
- [ ] Invalidation events use authoritative-refetch semantics where complete state is unsafe to publish.
- [ ] Strict generated TypeScript output is committed and stale output is rejected by tests.
- [ ] The editable React client keeps access JWTs only in memory, restores through the refresh credential on cold load, coordinates proactive refresh, and clears authentication on logout or terminal refresh failure.
- [ ] Contract and conformance tests cover declared fields, versioning, safe envelopes, and consumer-facing failures.

## Exclusions

No HTTP routes, websocket or Mercure transport, code generator hosting, framework-native session support, or
framework starter integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
