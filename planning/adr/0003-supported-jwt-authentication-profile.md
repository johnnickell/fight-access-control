# ADR 0003: Supported JWT Authentication Profile

- Status: accepted
- Date: 2026-08-19
- Amended: 2026-08-24

## Decision

Fight AccessControl owns the supported authentication lifecycle through one synchronous Application
`AuthenticationService`. Secret-bearing activation, login, refresh, logout, and password operations do not use
serializable CQRS messages. The service uses Fight Common password and token ports, creates authoritative
server-side refresh sessions, and returns immutable access-JWT and opaque refresh-credential results.

JWT signing keys, persistence, clients, HTTP actions and contracts, cookie construction, CORS/CSRF enforcement,
and runtime composition remain consumer concerns. Fight AccessControl owns claims, token lifetime policy,
refresh-session behavior, safe result contracts, and behavioral conformance tests.

Framework-native session authentication may be used independently by a consumer, but it is outside the
supported Fight AccessControl profile and receives no compatibility or conformance guarantee.

## Consequences

The JWT exclusion adopted from the earlier package-boundary wording is superseded. The PHP package still
contains only Domain and Application production namespaces and depends on no framework or Adapter layer.
Client applications and transport contracts are not package artifacts.
