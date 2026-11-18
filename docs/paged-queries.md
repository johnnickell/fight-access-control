# Paged Query result contracts

The six handlers below return `ResultSet<View>` or throw. They consume Common `^1.5.0`'s supported
`ResultSet::strict()` boundary on both received pages and mapped results. A native ResultSet is not proof of
strict construction: legacy construction is permissive, and even a strictly constructed page shares a mutable
ArrayList. Each invocation validates current local bounds before mapping and captures page membership with
`toArray()` before dependency calls. Mapping creates a new, exact View list; the returned list remains mutable.
This is not an immutable aggregate snapshot or protection against a caller mutating its own returned list.

| Query / handler | Repository read | Exact result / additional relation |
| --- | --- | --- |
| ListUsers / ListUsersHandler | UserRepository::getAll | ResultSet<UserView>; safe identities, states, Role IDs and dates |
| ListRoles / ListRolesHandler | RoleRepository::getAll | ResultSet<RoleView>; authoritative incarnation/revision and Permission IDs |
| ListPermissions / ListPermissionsHandler | PermissionRepository::getAll | ResultSet<PermissionView>; exact safe definitions |
| ListAgents / ListAgentsHandler | AgentRepository::getAll | ResultSet<AgentView>; one exact Permission batch, preserving each assignment's order and revisions |
| ListFeatures / ListFeaturesHandler | FeatureRepository::getAll | ResultSet<FeatureView>; original Permission binding/revision, explicit missing binding, wrong related identity rejected |
| ListActiveSessions / ListActiveSessionsHandler | RefreshSessionRepository::getByUserId | ResultSet<SessionView>; admitted User and usability at the single admitted read instant |

Every path preserves the requested page and page size exactly. Common owns positive bounds, nonnegative totals,
count no greater than page size or total, exact integer ceiling division, and zero total reporting one page.
Invalid local tuples throw Common DomainException. AccessControl rejects request mismatches as internal contract
faults, not not-found. Exact mapping signatures enforce aggregate elements; native collection annotations alone
are not runtime validation. No handler truncates a faulty page, substitutes metadata or conceals a failed dependency
as an empty success. Existing Agent exact-resolution and Feature binding checks remain their owners.

## Empty pages, order and persistence limits

Empty zero-total pages and empty out-of-range pages are valid, including pages greater than totalPages. Partial
pages with a positive total also remain valid. Strict construction imposes neither page-position/last-page remainder
rules nor equality between observed row count and a separately observed total. Count and rows need not be one
cross-read atomic snapshot. No read transaction, lock or stronger production persistence guarantee is added.

The existing repository signatures accept Pagination (including its ordering map), without defining a universal
field/comparator vocabulary. Handlers forward that exact request and preserve repository row order; they neither
sort nor reinterpret arbitrary consumer ordering fields. Consumers must qualify their supported ordering/filter
implementation and count/row semantics in their real persistence adapter. The only additional row selection promised
by these six package interfaces is the active-session owner/usability predicate, which the handler independently
checks. Pagination metadata never grants authority or infers a tenant filter.

## Admission, failures and consumers

Entry adapters retain authentication, Permission policy and target-scope authorization. Session self-service needs
no cross-user admission; cross-user inspection must pass the existing SessionAdministrationAuthorization before
clock/repository work. Each row must belong to the admitted User and satisfy `isUsableAt()` at that same instant;
expiry equality is unusable. Current-session marking remains safe identity comparison, not authority.

Queries do not mutate aggregates, commit, audit, clean up sessions or dispatch Events. No new grant or lifecycle
transition is introduced. Domain repositories stay nullable where already nullable. A missing Feature Permission
remains explicit binding information; a wrong Permission is an internal fault. Agent Permission batches must still
be complete and exact, never partial success. Unknown provider/native contract faults remain internal failures;
Common's ordinary safe-error policy presents generic 500 without provider diagnostics. Existing deliberate session
admission failure retains its existing category; this change adds no transport-specific exception or responder.

Consumers may continue supplying valid legacy-constructed ResultSets, but cannot rely on formerly unchecked invalid
metadata or unrelated/unusable session rows being disclosed. Project fixtures and examples retain their existing
signatures; no compatibility mode, shadow ResultSet or validation-provenance marker is supplied. Real configured
Common routing is tested, but generic QueryBus result inference remains TASK-00128's separate work. Package doubles
and static probes do not qualify actual consumer SQL, transport, authorization bootstrap or concurrency.

## Verification and ownership inventory

All six current handler declarations remain in their aggregate `Application/AccessControl/*/QueryHandler` homes;
none moved, appeared or disappeared. Each constructor, `queryRegistration()` and `handle()` was inspected, together
with the six Domain Query constructors/getters/fromArray/toArray methods, six View factories/getters/projections,
six paged repository methods, exact Agent resolver and Feature binding factory. Queries, Views and repository
signatures are unchanged; stricter validation belongs to the handler's request/result boundary.

`PagedQueryContractTest` exercises all six actual registered routes with legacy metadata faults, mutated incoming
strict pages, excessive pages, wrong erased elements, empty/partial/out-of-range results and operational failures.
It separately exercises the real Common factory, exact integer arithmetic/shared mutation, session predicates and
Feature related identity/callback mutation. Existing aggregate-owned tests retain safe shapes, Role revisions,
Agent exact batches, session self/admin admission and read-only journeys. `paged-query-positive.php` asserts six
exact handler ResultSets and six exact elements; existing Domain negative fixtures reject wrong element uses.

Direct callers remain the existing handler/conformance tests, Agent hydration checks and the new configured-route
contract suite; shipped payload/OpenAPI examples describe the same result shapes and require no wire-schema change. No production
Action, persistence adapter or alternate direct-handler runtime caller is added. The canonical Work handoff owns
the refreshed path/member/caller inventory, exact dependency/runtime identities, command logs, coverage and limits.
Independent Review and nonvisual QA remain separate acceptance obligations.
