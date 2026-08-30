# ADR 0006: Agent Principal and Observability Boundary

- Status: accepted
- Date: 2026-08-24

## Decision

Agent authentication returns a distinct immutable `AuthenticatedAgentPrincipal`. It contains the Agent ID, current
credential ID and revision, Permission-assignment revision, and direct Permission snapshots by ID and canonical
name. User and Agent principals share the narrow `PrincipalPermission` representation while remaining distinct
principal types. An Agent may answer whether that snapshot contains a named Permission, but it does not decide
consumer policy and has no package Roles.

A consumer composes a request-scoped `CurrentAgentPrincipalProvider`. It authenticates the consumer-supplied signed
request, revalidates credential and Permission-assignment authority, resolves the complete Permission snapshot, and
returns the same immutable Agent principal for the remainder of that request. No intermediate authentication result
is public. A nonce consumed after valid signature acceptance remains consumed if later authority resolution fails.
The flow does not reuse the User principal or refresh-session provider.

A consumer-selected `SecurityContext` contains exactly one authenticated User or Agent and delegates common Role and
Permission checks. The principal exposes its package-owned User-or-Agent type so a framework adapter can construct
the correct framework identity without coupling a package principal to a framework interface.

Every authentication or principal-resolution failure is generic and fail-closed at the caller boundary. The package
preserves server diagnosis through a secret-free Agent authentication diagnostic: a safe failure classification and
consumer correlation identifier. Diagnostics must exclude raw request data, signatures, nonces, and shared secrets.

Consumers own authentication-path selection, authorization policy, transport response mapping, framework identity
wrappers, and logging or metric delivery. Package behavior is limited to authoritative Agent authentication,
immutable authority snapshots, and Role or Permission presence.

## Consequences

Machine authority cannot be mistaken for a User refresh session, and a consumer can diagnose generic denials without
creating a credential or replay oracle. Frameworks remain free to choose their request lifecycle, logging, policy,
and denial behavior while binding the same package-level conformance outcomes.
