# Domain and Engineering Rules

When placing a class or choosing a dependency, read the normative
[Architecture rules](../../docs/engineering/standards/Architecture.md#accesscontrol-placement-and-dependency-rules),
[Naming](../../docs/engineering/standards/Naming.md#descriptive-collaborators-and-collections) and
[project profile](project-profile.md#package-boundary-and-contracts). They codify EPIC-00015's approved target.
The earlier two-production-layer/no-Adapter summary is superseded by WF-035 and EPIC-00015, not erased history;
[ADR 0001](../adr/0001-domain-application-package-boundary.md) retains the original decision and scoped amendments.

Current source has Domain/Application plus opt-in PSR-15, Symfony and Agent MCP adapters. TASK-00108 moves MCP
presentation/bindings/availability to `Adapter/Mcp/{Tool,Resource,Skill}/Agent`; authentication, Permission policy and
synchronized continuation stay inward. TASK-00109 groups Agent capabilities, Feature Discovery and authorization
Admission with descriptive collaborators; see the
[current composition map](../../docs/agent-integration.md#public-composition-map). Secret-bearing User operations
now use [direct authentication/session/password/email-confirmation capabilities](../../docs/user-authentication.md)
under TASK-00112, without the retired broad service. TASK-00107 changed guidance only. Consumers retain persistence,
policy/grants/target scope, route/bootstrap/key/queue/runtime composition and actual integration qualification.
A persistence placement pattern is not permission to implement production persistence here.

## Consumed Common contracts

The [complete affected contract inventory](../../docs/common-contracts.md) owns TASK-00110's canonical spelling,
getter, construction/serialization/error semantics and future-feature boundaries. [Composer](../../composer.json)
now requires `johnnickell/fight-common: ^1.5.1` for TASK-00089's scoped activity aggregates.
Exact-minimum and ordinary composition are qualified separately;
a manifest alone does not prove consumer readiness. The following table remains a compact ownership index.

Current minimum source is Common v1.5.1, `c73690164a9f112dbf14d774c56a7c01519b88aa`, not sibling HEAD.
The [execution guide](../../docs/agent-execution-reads.md) records inward admission/scope ownership and the
released activity-visibility correction; previous per-feature v1.5.0 qualification retains its original subject.
Supplier acceptance, release certification/publication and exact installed tag bytes resolve the historical supplier
hold. Commands still return void and UoW returns mixed. Independent adoption Review and behavioral QA remain required.

| Current AccessControl source | Actually consumed Common contracts | Coordination boundary |
| --- | --- | --- |
| [UserId](../../src/Domain/AccessControl/User/UserId.php), [UserRepository](../../src/Domain/AccessControl/User/UserRepository.php) | Domain UniqueId, EmailAddress, Pagination, ResultSet | Reuse low-level primitives; preserve repository atomic capabilities. No new local Store or UoW/repository combination. |
| [UserAuthentication](../../src/Application/AccessControl/User/Authentication/UserAuthentication.php), [TokenIssuer](../../src/Application/AccessControl/User/Authentication/TokenIssuer.php), [PasswordManagement](../../src/Application/AccessControl/User/PasswordManagement.php), [SessionLifecycle](../../src/Application/AccessControl/RefreshSession/SessionLifecycle.php), [EmailChangeConfirmation](../../src/Application/AccessControl/EmailChangeGrant/EmailChangeConfirmation.php) | Application Auth Security PasswordHasher/PasswordValidator/TokenEncoder, Repository TransactionalUnitOfWork, Messaging EventDispatcher | Keep supported synchronous sensitive inputs, actual transactions and post-commit policy. Native mixed UoW return plus known-local-callback @var is sufficient; no generic supplier wait. |
| [GetAgentProfileTool](../../src/Adapter/Mcp/Tool/Agent/GetAgentProfileTool.php), [UpdateAgentProfileTool](../../src/Adapter/Mcp/Tool/Agent/UpdateAgentProfileTool.php) | Application McpTool/McpToolInfo/McpToolOutput, McpProgressReporter, ApplicationData and QueryBus/SynchronousCommandBus | Adapter protocol presentation dispatches existing use cases through actual buses, without copied schemas or handler bypass. Canonical progress import is Application/Mcp/Tool/McpProgressReporter; its upstream alias preserves assignability. |
| [AgentToolPermissionCatalog](../../src/Adapter/Mcp/Tool/Agent/AgentToolPermissionCatalog.php) | McpToolRegistry, McpToolAvailability and exact immutable McpToolInfo identity | Common owns registry/protocol identity; AccessControl binds conjunctive Permission policy. Construction-only enumeration is not request authorization. |
| [Resource catalog](../../src/Adapter/Mcp/Resource/Agent/AgentResourcePermissionCatalog.php), [Resource availability](../../src/Adapter/Mcp/Resource/Agent/AgentResourceAvailability.php) | McpReadableResourceProvider, McpResourceInfo, McpResourceAvailability and Domain StrictJson | Preserve descriptor/provider identity, independent read admission and request-local authority; operational faults are not successful empty catalogs. |
| [Skill catalog](../../src/Adapter/Mcp/Skill/Agent/AgentSkillPermissionCatalog.php), [Skill availability](../../src/Adapter/Mcp/Skill/Agent/AgentSkillAvailability.php) | McpSkillRevision, McpSkillAvailability and Domain StrictJson | Whole-Skill and Resource admission stay independent; preserve revision binding and shared request-local authentication. |

[TASK-00110](../tasks/00110-TASK.md) owns the inventory, canonical adoption, supported minimum and ordinary/minimum
composition evidence. Its historical supplier hold is resolved by the published v1.5.0 evidence, not planning status. Common owns neutral ports, protocol engines and existing execution/Task/Notice
use cases; AccessControl must not copy them, introduce shadow lifecycle facades or use deprecated bridges as its
target. Common's released 1.x compatibility obligations remain upstream, not AccessControl aliases under ADR 0011.

[EPIC-00015's external observations](../epics/00015-EPIC.md#common-coordination-and-external-evidence) retain the
historical Common snapshot and planned foundation/Task/Notice/identity/Tool refactors. Those historical sibling plans are
not local blocked_by IDs; the current inventory distinguishes now-released supplier APIs from unimplemented local
features. Each future MCP family owns its
additional operation APIs and qualification. An unavailable contract holds only affected adoption; TASK-00108/00109
can use existing qualified APIs. John amended the separate Agent conversion to void Commands and independent
outcome Queries, invocable sync or async without transport assumptions; no result-return Common API is required.
[TICKET-00053](../tickets/00053-TICKET.md)/[TASK-00182](../tasks/00182-TASK.md) own conversion, while TASK-00130 separately
qualifies exact Command/Query typing. Original-key recovery and current execution/read authority remain required.
Do not implement conversion, guess a Common version or edit Common merely through this guidance amendment.

All production statements retain exact coverage, including Adapter; shipped OpenAPI contract integration tests
remain in the default suite. Current audit, independent authorization, atomicity, recovery and publication
obligations remain under the profile; approved future audit changes do not rewrite present source. Run the local
planning/documentation checks and canonical build under [Testing](../../docs/engineering/standards/Testing.md).
