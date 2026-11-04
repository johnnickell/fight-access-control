# Domain and Engineering Rules

When placing a class or choosing a dependency, read the normative
[Architecture rules](../../docs/engineering/standards/Architecture.md#accesscontrol-placement-and-dependency-rules),
[Naming](../../docs/engineering/standards/Naming.md#descriptive-collaborators-and-collections) and
[project profile](project-profile.md#package-boundary-and-contracts). They codify EPIC-00015's approved target.
The earlier two-production-layer/no-Adapter summary is superseded by WF-035 and EPIC-00015, not erased history;
[ADR 0001](../adr/0001-domain-application-package-boundary.md) retains the original decision and scoped amendments.

Current source has Domain/Application plus opt-in PSR-15 and Symfony adapters. Agent MCP presentation is still
in Application and secret-bearing User operations still use AuthenticationService. TASK-00108/00109/00112 own
those future relocations/decomposition; TASK-00107 changes guidance only. Consumers retain persistence,
policy/grants/target scope, route/bootstrap/key/queue/runtime composition and actual integration qualification.
A persistence placement pattern is not permission to implement production persistence here.

## Consumed Common contracts

These source-linked examples identify actual consumption at TASK-00107's base, not an exhaustive adoption
inventory or claims about future canonical APIs. Names below are current imports, not new names to copy blindly.
[Composer](../../composer.json) currently requires `johnnickell/fight-common: ^1.4`; a constraint alone does not
prove the installed package, a sufficient future minimum or every integration journey.

Work's ordinary dependency resolution inspected Common `v1.4.0`, source reference
`87b9580c072f3149d574e09865daa79620c92118` from its Composer metadata. Representative upstream definitions at that
reference are [McpProgressReporter](https://github.com/johnnickell/fight-common/blob/87b9580c072f3149d574e09865daa79620c92118/src/Application/Mcp/McpProgressReporter.php),
[CommandBus](https://github.com/johnnickell/fight-common/blob/87b9580c072f3149d574e09865daa79620c92118/src/Application/Messaging/Command/CommandBus.php),
[CommandHandler](https://github.com/johnnickell/fight-common/blob/87b9580c072f3149d574e09865daa79620c92118/src/Application/Messaging/Command/CommandHandler.php)
and [TransactionalUnitOfWork](https://github.com/johnnickell/fight-common/blob/87b9580c072f3149d574e09865daa79620c92118/src/Application/Repository/TransactionalUnitOfWork.php).
The inspected command contracts return void and UoW returns mixed. This bounded source observation supplies
neither a future canonical release nor TASK-00110's minimum/independent qualification; its hold remains unchanged.

| Current AccessControl source | Actually consumed Common contracts | Coordination boundary |
| --- | --- | --- |
| [UserId](../../src/Domain/AccessControl/User/UserId.php), [UserRepository](../../src/Domain/AccessControl/User/UserRepository.php) | Domain UniqueId, EmailAddress, Pagination, ResultSet | Reuse low-level primitives; preserve repository atomic capabilities. No new local Store or UoW/repository combination. |
| [AuthenticationService](../../src/Application/AccessControl/User/Security/AuthenticationService.php) | Application Auth Security PasswordHasher/PasswordValidator/TokenEncoder, Repository TransactionalUnitOfWork, Messaging EventDispatcher | Keep supported synchronous sensitive inputs, actual transactions and post-commit policy. Native mixed UoW return plus known-local-callback @var is sufficient; no generic supplier wait. |
| [GetAgentProfileTool](../../src/Application/AccessControl/Agent/Tool/GetAgentProfileTool.php), [UpdateAgentProfileTool](../../src/Application/AccessControl/Agent/Tool/UpdateAgentProfileTool.php) | Application McpTool/McpToolInfo/McpToolOutput, McpProgressReporter, ApplicationData and QueryBus/SynchronousCommandBus | TASK-00108 moves protocol presentation; dispatch existing use cases through actual buses, without copied schemas or handler bypass. Current progress import is Application/Mcp/McpProgressReporter, not proof of a future Tool home. |
| [AgentToolPermissionCatalog](../../src/Application/AccessControl/Agent/AgentToolPermissionCatalog.php) | McpToolRegistry, McpToolAvailability and exact immutable McpToolInfo identity | Common owns registry/protocol identity; AccessControl binds conjunctive Permission policy. Construction-only enumeration is not request authorization. |
| [Resource catalog](../../src/Application/AccessControl/Agent/AgentResourcePermissionCatalog.php), [Resource availability](../../src/Application/AccessControl/Agent/Security/AgentResourceAvailability.php) | McpReadableResourceProvider, McpResourceInfo, McpResourceAvailability and Domain StrictJson | Preserve descriptor/provider identity, independent read admission and request-local authority; operational faults are not successful empty catalogs. |
| [Skill catalog](../../src/Application/AccessControl/Agent/AgentSkillPermissionCatalog.php), [Skill availability](../../src/Application/AccessControl/Agent/Security/AgentSkillAvailability.php) | McpSkillRevision, McpSkillAvailability and Domain StrictJson | Whole-Skill and Resource admission stay independent; preserve revision binding and shared request-local authentication. |

[TASK-00110](../tasks/00110-TASK.md) owns the actual exhaustive consumed-contract inventory, canonical adoption,
supported minimum and ordinary/minimum-lane composition proof. Before adoption verify implemented APIs,
independent acceptance/qualification, actual tags and sufficient minimum; neither these examples nor a current
import clears its needs-info hold. Common owns neutral ports, protocol engines and existing execution/Task/Notice
use cases; AccessControl must not copy them, introduce shadow lifecycle facades or use deprecated bridges as its
target. Common's released 1.x compatibility obligations remain upstream, not AccessControl aliases under ADR 0011.

[EPIC-00015's external observations](../epics/00015-EPIC.md#common-coordination-and-external-evidence) retain the
historical Common snapshot and planned foundation/Task/Notice/identity/Tool refactors. Those sibling plans are
external evidence, not local blocked_by IDs or proof of tagged availability. Each future MCP family owns its
additional operation APIs and qualification. An unavailable contract holds only affected adoption; TASK-00108/00109
can use existing qualified APIs. The separate Agent command conversion still needs supported result-preserving
synchronous dispatch; the current void contracts cannot supply its confirmed/indeterminate/publication-warning
result. Do not implement that conversion, guess a Common version or edit Common through this guidance task.

All production statements retain exact coverage, including Adapter; shipped OpenAPI contract integration tests
remain in the default suite. Current audit, independent authorization, atomicity, recovery and publication
obligations remain under the profile; approved future audit changes do not rewrite present source. Run the local
planning/documentation checks and canonical build under [Testing](../../docs/engineering/standards/Testing.md).
