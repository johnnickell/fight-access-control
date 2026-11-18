# Agent issuance dispatch contracts

TASK-00130 qualifies the two converted issuance Commands and their independent outcome Query. The accepted
TASK-00182 conversion subject is `4990103d519bdd0a1bbb7d77bccf10619607f4cc` against
`2705dcc2fe10489080113489488b6f124b47c4cb`; its independent Review/QA and subsequent Land integration own
business acceptance. Current issuance failure contracts additionally follow TASK-00143. This slice changes no
business behavior, Common API, consumer, transport or execution mode.

## Complete binding and caller map

| Payload / owning path | Registration / Application handler | Concrete input and result | Callers |
| --- | --- | --- | --- |
| [ProvisionAgent](../src/Domain/AccessControl/Agent/Command/ProvisionAgent.php) | [ProvisionAgentHandler](../src/Application/AccessControl/Agent/CommandHandler/ProvisionAgentHandler.php)::commandRegistration() = ProvisionAgent::class | CommandMessage; flat local ProvisionAgent extraction; handle(): void | Consumer composition in [provisioning](agent-provisioning-operations.md#public-boundary); controlled ProvisioningEnvironment and IssuanceBusFixture in tests |
| [RotateAgentCredential](../src/Domain/AccessControl/Agent/Command/RotateAgentCredential.php) | [RotateAgentCredentialHandler](../src/Application/AccessControl/Agent/CommandHandler/RotateAgentCredentialHandler.php)::commandRegistration() = RotateAgentCredential::class | CommandMessage; flat local RotateAgentCredential extraction; handle(): void | Consumer composition in [rotation](agent-rotation-operations.md); controlled RotationEnvironment and IssuanceBusFixture in tests |
| [GetAgentOperation](../src/Domain/AccessControl/AgentCredentialOperation/Query/GetAgentOperation.php) | [GetAgentOperationHandler](../src/Application/AccessControl/AgentCredentialOperation/QueryHandler/GetAgentOperationHandler.php)::queryRegistration() = GetAgentOperation::class | QueryMessage; flat local GetAgentOperation extraction; handle(): AgentOperationView or throws | Both guides above, [Query guide](query-dispatch-contracts.md), IssuanceBusFixture::dispatchAndRead() and dispatch/business tests |

There are no production `src/` callers of these three payload constructors or bus invocations. External consumers
configure their own composition; package tests and maintained examples are the owned callers. No additional Query
is needed. Existing delivery discovery/maintenance Queries retain their separate owners; the other 37 Command paths
retain TASK-00129 and all 22 Query paths retain TASK-00128.

Provision retains AgentOperationKey and AgentProvisioningRequest, including the original bounded name and registered
AgentCredentialDestination. Rotation retains AgentOperationKey and AgentRotationRequest: exact AgentId,
AgentCredentialId predecessor, integer predecessor revision and destination. Strict `fromArray()` / `toArray()`
contracts retain scoped namespace/caller/operation identity and original intent. Neither contains a secret,
authorizing actor assertion, caller digest or transport selection. Query retains key and destination only.

## Released Common composition and metadata

Common `^1.5.0` is already required. The tagged minimum **v1.5.0**, source
`06fb5cd3720af9cf8295198f74040d95b64c5155`, is the released baseline adopted by TASK-00110; see the
[consumed API inventory](common-contracts.md). Historical TASK-00182 Common v1.4 lanes are not this slice's v1.5 proof.

`IssuanceBusFixture` registers each production CommandHandler using its own declaration with Common
`InMemoryCommandRouter` → `RoutingCommandBus`, and registers the production GetAgentOperationHandler with
`InMemoryQueryRouter` → `RoutingQueryBus`. Its Query constructor takes only the Domain read repository and current
authorization, not a material-bearing repository requirement. No dummy/forwarding handler or local bus is involved.

- CommandBus::execute(Command): void creates a CommandMessage; dispatch(CommandMessage): void passes that message
  to the matched handler. Native SynchronousCommandBus is the concrete test composition, not an async guarantee.
- QueryBus::fetch(Query): mixed creates a QueryMessage; dispatch(QueryMessage): mixed returns the matched handler's
  actual result. Neither method infers a generic result. The concrete handler returns AgentOperationView.
- CommandMessage/QueryMessage::create() generate envelope ID and timestamp. `withMeta()` replaces metadata;
  `mergeMeta()` merges it with later values winning. New envelopes preserve ID, timestamp and payload;
  metadata input/read copies cannot mutate the retained envelope. The serialization round trip preserves actual
  payload class, canonical payload, message type, ID, timestamp and metadata through the configured buses.
- Envelope IDs and correlation metadata are not operation keys or execution/read authority. Deserialization is a
  trusted composition seam here, not permission to instantiate arbitrary untrusted payload classes. Consumers own
  admission/allowlisting of their wire boundary; a PHPDoc is not runtime validation.

## Precise outcomes without a Command result

```php
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

// $command is the retained original ProvisionAgent or RotateAgentCredential intent.
$message = CommandMessage::create($command)->withMeta(Meta::create(['correlation' => 'safe-correlation']));
$commandBus->dispatch($message); // void; not issuance or a publication-warning response
$query = new GetAgentOperation($command->getKey(), $command->getRequest()->getDestination());
/** @var AgentOperationView $view Known matching GetAgentOperationHandler registration */
$view = $queryBus->dispatch(QueryMessage::create($query)->withMeta($message->meta()));
$issuance = $view->getIssuance(); // AgentIssuance|null, not a Command return
if ($issuance !== null) {
    $credentialId = $issuance->getCredentialId(); // AgentCredentialId, original issuance only
}
// getDeliveryDisposition(): AgentDeliveryDisposition|null
// getCredentialDisposition(): AgentCredentialDisposition|null
// fetch($query) supports the same known-caller annotation, but remains natively mixed.
```

Known matching caller annotations describe the trusted binding, not automatic inference or validation of dynamic
results. AgentOperationView's native optional getters require explicit handling: `isConfirmed()` is not a PHPStan
assertion that narrows another getter. Indeterminate carries null issuance and both null dispositions; confirmed
exposes original AgentIssuance and separate recorded delivery/credential dispositions. A genuinely dynamic caller
must validate its result at its owning boundary. Query repository contracts and handler correlation/current
read-authorization checks remain in force; no new unsafe cast or suppression is added.

Submission, delayed execution, confirmed commit and authorized read observation are separate facts. Before execution,
or after a genuinely unknown outcome, indeterminate is neither rollback nor rejection nor queue state. Retain the
same key and original rotation predecessor. Execution faults need not reach an async submitter. Confirmed issuance
survives post-commit publication failure, including both publishers failing, without returned/reconstructed warnings.
It establishes neither delivery nor activation nor permission to use the credential. Queries remain read-only.

## Executable qualification and evidence boundaries

- [Positive static fixture](../type-contracts/agent-dispatch-positive.php): exact native messages, payload annotations,
  key/request/predecessor, void bus/handler closures, concrete handler View, explicitly mixed bus results and precise
  known-caller View / optional issuance / dispositions / credential identity.
- [Negative static fixture](../type-contracts/agent-dispatch-negative.php): exact diagnostic identifiers, lines and
  messages for using void execute/dispatch as issuance, crossing Command/Query message or bus families, ignoring
  optional issuance, confusing disposition enums and supplying a provisioning request to rotation. The
  [case manifest](../type-contracts/cases.json) expects 23 positive probes and 11 negative diagnostics; arbitrary
  analyzer failure is not success. No ignore/baseline or mismapped-router rejection is used.
- [Dispatch contract test](../tests/Application/AccessControl/Agent/CommandHandler/AgentIssuanceDispatchContractTest.php):
  all three expected registrations/native returns, both real Commands through execute/serialized dispatch, both
  Query methods after commit and a pre-execution Query observation, concrete optional outcomes and immutable metadata, with ordinary and failed
  publication. It reuses the conversion's real environments, repositories and independent Query oracle.
- Existing AgentIssuanceDispatchTest retains delayed serialization/restart/current worker authority, denied reads,
  original-key redelivery and competing predecessor proof. ProvisionAgentHandlerTest and RotateAgentCredentialHandlerTest
  retain both uncertain-commit outcomes and existing business/fault matrices. IssuanceRecoveryConformance retains
  scheduler-only recovery. This slice does not duplicate or replace those acceptance suites.

Run `./bin/type-contracts`, the focused Agent CommandHandler and AgentCredentialOperation QueryHandler suites at
supported-minimum and restored ordinary dependencies, then normal `./bin/build` and planning/documentation checks.
The canonical TASK-00130 Work handoff records actual lock/source/runtime identities, counts, complete logs, exits,
coverage and advisories. Independent Review and nonvisual behavioral QA remain separate. Controlled package fixtures
are not consumer storage, concurrency, cryptography, authority-writer, broker, sink, restart/deployment or use proof.
