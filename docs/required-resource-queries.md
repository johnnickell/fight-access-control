# Required-resource Queries (unreleased)

TASK-00131 implements the six required-resource paths below. Register each public `queryRegistration()` with the
consumer's supported Common `QueryBus`; use `fetch($query)` or `dispatch(QueryMessage::create($query))`. Query
payload identities and canonical `fromArray()`/`toArray()` shapes are unchanged. The current routing API returns
native `mixed`; this semantic slice does not establish generic bus inference (TASK-00128).

| Domain Query | Application handler owner | Exact successful result |
| --- | --- | --- |
| `User\Query\GetUserById` | `User\QueryHandler\GetUserByIdHandler` | `User\Query\UserView` |
| `Role\Query\GetRoleById` | `Role\QueryHandler\GetRoleByIdHandler` | `Role\Query\RoleView` |
| `Permission\Query\GetPermissionById` | `Permission\QueryHandler\GetPermissionByIdHandler` | `Permission\Query\PermissionView` |
| `Agent\Query\GetAgentById` | `Agent\QueryHandler\GetAgentByIdHandler` | `Agent\Query\AgentView` |
| `Agent\Query\GetAgentProfile` | `Agent\QueryHandler\GetAgentProfileHandler` | `Agent\Query\AgentProfileView` |
| `Feature\Query\GetFeatureById` | `Feature\QueryHandler\GetFeatureByIdHandler` | `Feature\Query\FeatureView` |

Prefixes are `Fight\AccessControl\Domain\AccessControl` and `Fight\AccessControl\Application\AccessControl`.
This is the current six-path manifest: no moved, removed or added handler is hidden by the historical names.

## Results, failure intent and authority

- Each handler reads the corresponding nullable Domain repository and compares the returned identity with the
  requested purpose-specific identity before projection or related Permission reads. Native aggregate-or-null
  return contracts remain in force; no duplicate native type checks or repository wrappers are added.
- Deliberate absence throws `Fight\Common\Application\Error\Exception\ResourceNotFound` in Application.
  Domain exceptions do not import/extend Application exceptions. Missing and revoked Agent profiles use the
  identical category/message/code/cause; revoked Agents remain available to the separate administrative query.
  A wrong identity is internal even when that unrelated Agent is revoked.
- Wrong identity raises `UnexpectedValueException`; invalid Agent Permission snapshots retain `AgentReadException`;
  invalid Feature binding identity retains `FeatureStateException` at `FeatureView::fromFeature()`. These are internal
  faults, not deliberate missing-resource outcomes. Native return violations and arbitrary infrastructure failures
  propagate; no diagnostic text/code classification, blanket business wrapper, empty success or fabricated 404.
- Feature binding absence is **not Feature absence**: its safe View retains the stored `permission_id`, null
  `permission_name` and `binding_missing: true`. Wrong binding identity fails instead of relabeling it missing.
- Agent administrative results still resolve exact complete Permission definitions through the existing resolver,
  preserving assignment order and secret-free fields. Role Views retain current revision/incarnation facts.
- All six remain read-only: no persistence mutation, transaction/commit, audit insertion or Event dispatch. Profile
  reads resolve fresh state each time, never reuse a cached name or permission snapshot.
- IDs are not authentication or permission. Consumer entries retain their authentication, caller/target authorization
  and scope checks. The profile Tool still selects only its authenticated principal's ID, through existing protected
  Tool admission. No actor field, grant, authorization fallback or transport bootstrap is introduced.

## Breaking consumer obligations

Remove nullable-success branches for the first five Queries; catch intentional `ResourceNotFound` where a missing
resource is deliberately presented. Replace query-specific catches of `FeatureNotFoundException` for Feature detail;
that Domain exception remains in use by other Feature operations and is not globally reclassified. Domain repository
callers still handle null. Do not convert all `LookupException`, Domain exceptions, `TypeError` or storage faults to
not-found. Missing routing is configuration failure, not resource absence. ADR 0011 supplies no nullable mode,
alias, historical reader or migration/backfill contract. No consumer repository is modified or qualified here.

Ordinary HTTP composition explicitly selects Common's `SafeJSendErrorMiddleware` / `SafeHttpErrorPolicy` and one
trusted redaction-aware diagnostics owner: ResourceNotFound becomes fixed safe 404; unknown/internal faults become
fixed safe 500. Never expose messages, codes, causes, traces, payloads or credentials. These handlers return no HTTP
responses and install no middleware. Existing native safe integrations remain separate opt-in compositions.

MCP is **not** an HTTP/JSend envelope. `AgentProfileToolFailures` now maps exact Common `ResourceNotFound` to
`The Agent profile is unavailable.`; obsolete Tool-level null conversion and generic LookupException binding are
removed. Common's central MCP boundary retains internal-fault handling. OAuth is untouched. Broader protocol
reconciliation remains TICKET-00041-owned.

## Verification and limits

`tests/Adapter/Query/RequiredResourceQueryTest.php` configures the real supported Common router/bus and safe PSR-15
middleware around all six production handlers. It exercises fetch and reconstructed-message dispatch, exact results,
read-only behavior, absence, wrong identities, native return violations and operational faults. Additional cases
separate missing/wrong Feature binding, invalid Agent snapshots and revoked/wrong-revoked profiles. Existing owning
handler, registration/recovery, profile Tool and Permission-admission tests retain their business/security proof.

The declared Common floor remains `^1.4.1`. Its released `v1.4.1` source includes the Application failure and safe
PSR-15 APIs (introduced in v1.4.0). Work evidence records actual tagged identities and sufficient-minimum/ordinary
resolutions and executions; source inspection alone is not proof. No Common implementation or new supplier API is
needed. The unchanged mixed bus API proves runtime routing, not exact static inference through the generic bus.

Canonical Work evidence retains complete inventories/caller dispositions, regression-first Before/After proof,
logs/exits/gate receipt, exact owned statement coverage and runtime provenance. Independent Review and nonvisual
QA remain required. Modeled repositories and package transport composition do not qualify consumer persistence,
production routes, diagnostics sinks, framework bootstrap, external authority or deployment.
