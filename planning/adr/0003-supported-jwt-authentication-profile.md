# ADR 0003: Supported JWT Authentication Profile

- Status: accepted
- Date: 2026-08-19
- Amended: 2026-08-24

## Approved placement amendment — EPIC-00015 / TASK-00107

[EPIC-00015](../epics/00015-EPIC.md) replaces the original *single AuthenticationService* placement mandate,
not synchronous execution or the supported JWT profile. Follow the current
[Architecture rule](../../docs/engineering/standards/Architecture.md#application-mirrored-ownership-and-cohesive-capabilities):
authentication/token issuance, refresh-session, password and email-confirmation capabilities have cohesive direct
owners and actual dependencies, without a retained forwarding facade. TASK-00112 implements that replacement
after TASK-00111; AuthenticationService is still present source, not retroactively nonconforming implementation.

Raw secrets remain SensitiveParameter, non-serializable inputs, absent from messages/facts/diagnostics. Preserve
safe token/conflict/void results, login dummy-hash/throttle/rehash, grant single use, session lifetime/replay/logout,
authority revisions/revocation, actual repository CAS/coupled insertion and audit atomicity. Rollback, uncertain
commit and post-commit publication retain distinct current outcomes; notification failure cannot replace a
primary fault or broaden the scoped Agent issuance-warning exception. Current consumer authorization, keys,
cookies, CORS/CSRF and actual storage/bootstrap qualification remain outside the placement amendment.

WF-035 and EPIC-00015 also supersede the Domain/Application-only/no-Adapter consequence below within the narrow
optional integration boundary recorded in [ADR 0001](0001-domain-application-package-boundary.md#current-applicability).
The original decision remains historical; this guidance does not implement later capabilities or qualify consumers.

## Original decision — preserved history

Fight AccessControl owns the supported authentication lifecycle through one synchronous Application
`AuthenticationService`. Secret-bearing activation, login, refresh, logout, and password operations do not use
serializable CQRS messages. The service uses Fight Common password and token ports, creates authoritative
server-side refresh sessions, and returns immutable access-JWT and opaque refresh-credential results.

JWT signing keys, persistence, clients, HTTP actions and contracts, cookie construction, CORS/CSRF enforcement,
and runtime composition remain consumer concerns. Fight AccessControl owns claims, token lifetime policy,
refresh-session behavior, safe result contracts, and behavioral conformance tests.

Framework-native session authentication may be used independently by a consumer, but it is outside the
supported Fight AccessControl profile and receives no compatibility or conformance guarantee.

## Original consequences

The JWT exclusion adopted from the earlier package-boundary wording is superseded. The PHP package still
contains only Domain and Application production namespaces and depends on no framework or Adapter layer.
Client applications and transport contracts are not package artifacts.
