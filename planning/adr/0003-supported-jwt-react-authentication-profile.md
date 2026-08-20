# ADR 0003: Supported JWT and React Authentication Profile

- Status: accepted
- Date: 2026-08-19

## Decision

Fight AccessControl owns the supported authentication lifecycle through one synchronous Application
`AuthenticationService`. Secret-bearing activation, login, refresh, logout, and password operations do not use
serializable CQRS messages. The service uses Fight Common password and token ports, creates authoritative
server-side refresh sessions, and returns immutable access-JWT and opaque refresh-credential results.

The supported browser profile is a complete editable React client. It keeps the 15-minute access JWT only in
memory and sends the opaque refresh credential through a secure HttpOnly cookie constructed by the consumer HTTP
adapter. Ordinary refresh sessions use one-day idle and two-day absolute expiry. Remembered sessions use 15-day
idle and 30-day absolute expiry. Cold loads refresh before authenticated rendering, and proactive refresh begins
at ten minutes.

JWT signing keys, persistence, HTTP actions, cookie construction, CORS/CSRF enforcement, and runtime composition
remain consumer adapters. Fight AccessControl owns the claims, token lifetime policy, refresh-session behavior,
safe result contracts, React lifecycle, and conformance tests.

Framework-native session authentication may be used independently by a consumer as a fallback, but it is outside
the supported Fight AccessControl profile and receives no compatibility or conformance guarantee.

## Consequences

The JWT and client exclusions adopted from the earlier package-boundary wording are superseded. The PHP package
still contains only Domain and Application production namespaces and depends on no framework or Adapter layer.
The editable React client is a source artifact rather than a PHP Adapter.
