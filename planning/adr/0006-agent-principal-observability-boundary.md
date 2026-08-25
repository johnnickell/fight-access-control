# ADR 0006: Agent Principal and Observability Boundary

- Status: accepted
- Date: 2026-08-24

## Decision

Agent authentication returns a distinct immutable `AuthenticatedAgentPrincipal`. It contains the Agent ID, current
credential ID and revision, Permission-assignment revision, and direct Permission snapshots by ID and canonical
name. It may answer whether that snapshot contains a named Permission, but it does not decide consumer policy.

A consumer composes a request-scoped `CurrentAgentPrincipalProvider`. It authenticates the consumer-supplied signed
request once and returns the same immutable Agent-principal snapshot for the remainder of that request. It does not
reuse the User `AuthenticatedPrincipal` or refresh-session provider.

Every authentication or principal-resolution failure is generic and fail-closed at the caller boundary. The package
preserves server diagnosis through a secret-free Agent authentication diagnostic: a safe failure classification and
consumer correlation identifier. Diagnostics must exclude raw request data, signatures, nonces, and shared secrets.

Consumers own authorization policy, transport response mapping, and logging or metric delivery. Package behavior is
limited to authoritative Agent authentication and direct-Permission presence in the immutable snapshot.

## Consequences

Machine authority cannot be mistaken for a User refresh session, and a consumer can diagnose generic denials without
creating a credential or replay oracle. Frameworks remain free to choose their request lifecycle, logging, policy,
and denial behavior while binding the same package-level conformance outcomes.
