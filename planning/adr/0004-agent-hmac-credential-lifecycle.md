# ADR 0004: Agent HMAC Credential Lifecycle

- Status: accepted
- Date: 2026-08-24

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
