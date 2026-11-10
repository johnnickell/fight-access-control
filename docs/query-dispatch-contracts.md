# Query dispatch contracts

## Supported boundary

Common `^1.5.0` supplies the ordinary `Query`, `QueryMessage`, `QueryHandler`, `QueryBus`,
`InMemoryQueryRouter`, `RoutingQueryBus` and optional `QueryPipeline` APIs used here. Both bus invocation
methods deliberately return native/static `mixed`. There is no generic Query-to-result inference contract.
John's 2026-11-09 amendment permits a caller annotation for the known registered handler's result:

```php
use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/** @var ResultSet<UserView> $users */
$users = $queryBus->fetch(new ListUsers($pagination));
$message = QueryMessage::create(new ListUsers($pagination))
    ->withMeta(Meta::create(['origin' => 'administration']))
    ->mergeMeta(Meta::create(['correlation' => 'safe-example']));
/** @var ResultSet<UserView> $dispatchedUsers */
$dispatchedUsers = $queryBus->dispatch($message);
```

This is an owned caller assertion, not runtime generic enforcement or proof that arbitrary injected buses return
that value. Compose exactly the corresponding handler. Each concrete handler already declares a specific native
result; richer collection/list contracts declare their elements. Result declarations and the two existing production
callers need no implementation change for this amendment. The subsequent payload-style clarification adds the
missing local annotations to preparation and preview; all other 20 handlers already match. No new Common API, copied bus, handler bypass, result
wrapper, ignore or compatibility path is introduced. Dynamic external decoding still needs owner validation.

## Exhaustive current bindings

The candidate contains 22 Queries and 22 QueryHandlers, unchanged in count from planning. Operation-owned Queries
and handlers now live under `AgentCredentialOperation`, not `Agent`. The following path convention is exact:
`src/Domain/AccessControl/<Owner>/Query/<Query>.php` and
`src/Application/AccessControl/<Owner>/QueryHandler/<Query>Handler.php`. Every handler implements Common
`QueryHandler`, takes `QueryMessage`, and `queryRegistration()` returns exactly its Query class. The native
registration return is `string`. In every row, the incoming payload type is exactly the Query / registration class
shown below: the same owning handler declares `queryRegistration()` and a flat single-line
`/** @var <Query> $query */` immediately above `$query = $queryMessage->payload();` inside `handle()`.
Preparation and preview retain their payload guards against `self::queryRegistration()` using native `is_a`,
preserving wrong-message rejection and its exception before business work without analysis suppressions. The canonical shared
[PHP convention](engineering/standards/PHP.md#message-payload-declarations) also covers CommandHandlers and Event
Subscribers; their source qualification is separately owned. No upstream generic registration guarantee is claimed.

| Owner | Query / registration class | Native result | Precise static result |
| --- | --- | --- | --- |
| User | GetUserById | UserView | UserView |
| User | ListUsers | ResultSet | ResultSet<UserView> |
| Role | GetRoleById | RoleView | RoleView |
| Role | ListRoles | ResultSet | ResultSet<RoleView> |
| Permission | GetPermissionById | PermissionView | PermissionView |
| Permission | ListPermissions | ResultSet | ResultSet<PermissionView> |
| Agent | GetAgentById | AgentView | AgentView |
| Agent | GetAgentProfile | AgentProfileView | AgentProfileView |
| Agent | ListAgents | ResultSet | ResultSet<AgentView> |
| AgentCredentialOperation | GetAgentOperation | AgentOperationView | AgentOperationView |
| AgentCredentialOperation | ListDueAgentDeliveries | array | list<AgentOperationView> |
| AgentCredentialOperation | ListAgentDeliveryMaintenance | array | list<AgentOperationView> |
| AgentCredentialOperation | CountAgentDeliveryKeyReferences | int | int |
| Feature | GetFeatureById | FeatureView | FeatureView |
| Feature | ListFeatures | ResultSet | ResultSet<FeatureView> |
| Feature | ValidateFeaturePreparation | FeaturePreparationResult | FeaturePreparationResult |
| ManagedPolicy | PreviewManagedPolicy | ManagedPolicyPlan | ManagedPolicyPlan |
| RefreshSession | ListActiveSessions | ResultSet | ResultSet<SessionView> |
| ActivationGrant | FindInvitationDeliveryStatus | InvitationDeliveryStatusView or CredentialDeliveryAbsent | same union |
| CredentialDelivery | FindCredentialDeliveryStatus | CredentialDeliveryStatusView or CredentialDeliveryAbsent | same union |
| CredentialDelivery | FindDueCredentialDeliveries | array | list<DueCredentialDelivery> |
| CredentialDelivery | FindExpiredCredentialDeliveries | array | list<ExpiredCredentialDelivery> |

Views/results live beside each Query except `CredentialDeliveryAbsent`, which is shared by the two credential
status families, `DueCredentialDelivery`/`ExpiredCredentialDelivery` under `CredentialDelivery`, and
`ManagedPolicyPlan` under `ManagedPolicy`. Results include intentional absence and indeterminate operation state;
neither is reinterpreted here. TASK-00132 owns page validation/strict construction/mutable exposure semantics.
This slice uses the actual integrated current declarations without duplicating that concurrent work.

## Callers and composition

There are exactly two production QueryBus callers:

- `src/Adapter/Mcp/Tool/Agent/GetAgentProfileTool.php`: `GetAgentProfile`, known `AgentProfileView` annotation,
  current principal resolution before the read, exact safe projection afterward. The annotation is permitted;
  this task neither removes it nor duplicates TASK-00147's broader Tool assessment.
- `src/Application/AccessControl/AgentCredentialOperation/Delivery/AgentDeliveryRecoveryService.php`:
  `ListDueAgentDeliveries`, runtime-validated worker list. This deliberate injected/dynamic boundary retains its
  list/element validation and current authorization/recovery behavior from TASK-00144; no second constructor or
  routing migration is performed here.

The other 20 Query routes are public consumer composition points, not absent implementations of package Actions.
The library has no central production handler registry. Consumers register `handler::queryRegistration()` with
that handler in supported Common composition and retain their own grants/target scope/bootstrap ownership.
`tests/Adapter/Query/QueryDispatchContractTest.php` explicitly registers and exercises **every row**, using real
handlers, both `RoutingQueryBus` and `QueryPipeline`, interface-typed invocation, fetch, metadata-transformed
dispatch and canonical message round trips. Repository/provider doubles and the existing Agent maintenance
fixture are package composition evidence, not consumer persistence qualification.

Existing semantic proof remains required alongside that exhaustive dispatch matrix:

| Rows | Existing behavior/caller proof |
| --- | --- |
| Six required Views | `tests/Adapter/Query/RequiredResourceQueryTest.php`, corresponding Application handler tests |
| Six paged results | `tests/Application/AccessControl/{User,Role,Permission,Agent,Feature,RefreshSession}/QueryHandler/` |
| Four operation reads | `tests/Application/AccessControl/AgentCredentialOperation/QueryHandler/AgentQueryContractTest.php` and `AgentMaintenanceQueryTest.php` |
| Four credential reads | `tests/Application/AccessControl/CredentialDelivery/QueryHandler/CredentialQueryCompositionTest.php` and `CredentialQueryContractTest.php`, ActivationGrant handler tests |
| Preparation and preview | `tests/Adapter/Query/PreparationPreviewQueryTest.php` and their Application handler tests |
| Production callers | Agent profile Tool tests and Agent recovery/delivery/conformance tests |

Current guide caller examples are `agent-profile.md`, `agent-operation-status.md`, `agent-provisioning-operations.md`,
`agent-rotation-operations.md`, `feature-preparation.md`, and the HTTP standard's User example. Their known results
now carry matching annotations. The complete candidate construction/reference inventory (including tests, static
fixtures and guide examples) is retained with the TASK Work evidence; documentation references are not runtime callers.

## Executable proof and limits

`type-contracts/query-dispatch-positive.php` contains 82 exact probes: all 22 direct declared results, all 22
annotated fetch results, all 22 annotated metadata-dispatch results, ten collection/list elements, and six explicit
`mixed` results across interface/routing/pipeline fetch and dispatch. These separately prove declaration precision
and caller annotation use; they do not claim inference from a mixed bus. The negative file checks 28 exact
rejections: all 22 declared results used as an incompatible object, non-Query payload/fetch, non-message dispatch,
non-handler registration, and wrong element use after annotated fetch and transformed dispatch. The existing
required runner matches every diagnostic and executes through `bin/quality`/`bin/build`.

Common's router accepts a string-to-QueryHandler map; it does not statically relate an arbitrary Query class to a
particular concrete handler or enforce its registration declaration. The exhaustive runtime matrix checks our
actual registrations, rather than claiming unsupported generic rejection. Native PHP rejects non-Query message
payloads and non-handler registration; unconfigured routing throws rather than fabricating an empty result.
The retained all-handler level-8 analysis checks incoming local payload use as well as exact results. Direct
source convention validation inventories all 22 registration/annotation/extraction pairs in Work evidence; it is
not a product PHPUnit test of source text. Existing required static fixtures and the real 22-binding matrix remain
the executable contract proof, rather than adding assertion-only probes or a second fixture framework. An added
matrix scenario registers each handler alone and proves an unrelated Query cannot reach it through either bus.
All six paged handlers additionally preserve a primary repository failure through both real bus compositions and
both invocation methods. A pipeline test independently checks exact message forwarding and primary throwable identity. Existing semantic
suites cover actual handler/provider failure ordering, intentional safe failures, concealment, read-only behavior
and redaction (including operation reads' intentional sanitization rather than raw throwable propagation).

Metadata replacement/merge keeps identity, timestamp and payload, leaves the original unchanged, and round-trips
canonical wire data for every binding. No raw secret becomes serializable. The two payload guards still reject before business work with the same exceptions; local style and the guard's
source of the supported class change, not the accepted payload. No permission/grant,
mutation, transaction, audit insertion or Event dispatch changes. Nonvisual executable evidence is appropriate for
this library contract; screenshots would not prove its types or dispatch behavior. Independent Review and nonvisual
QA, exact dependency/runtime evidence and the full gate remain distinct Work handoff obligations.
