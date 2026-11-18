# Agent-scoped execution and timeline reads

Compose `AgentExecutionReadAdmission` with Common's real execution handlers to intersect direct Agent Permissions
with trusted consumer relationship scope. The package requires Common **^1.5.1**, first sufficient scoped-aggregate
minimum [v1.5.1](https://github.com/johnnickell/fight-common/releases/tag/v1.5.1), source
`c73690164a9f112dbf14d774c56a7c01519b88aa`. v1.5.0 supplied the APIs but did not constrain derived inspection by
activity grants. The [contract inventory](common-contracts.md) records supplier acceptance and qualification.

## Ownership and composition

Production types live inward in `Application/AccessControl/Agent/Execution`:

- `AgentExecutionScope::currentAccess(AuthenticatedAgentPrincipal, ExecutionSelection): ExecutionAccess` is the
  consumer-owned relationship/reference policy port. It supplies authoritative project/session, phase, activity
  and independent reference grants. The request selection identifies wanted data, never authority.
- `AgentExecutionReadAdmission` implements Common's `ExecutionReadAdmission`. Its constructor takes the ordinary
  request provider, signed request, correlation ID, scope, read requirements and reference requirements. Both
  requirement arrays must be nonempty lists of unique canonical `PermissionName` values from trusted configuration.
  Every read Permission is required before calling scope. Missing read Permission returns empty access. Missing
  reference Permission removes only `detailGrants`; all narrower project/session/phase/activity decisions remain.
  Policy/authentication faults propagate; they are not converted to empty successful results.

Use separate consumer-defined coarse Tool, business read and reference-disclosure requirements where appropriate.
The package adds no Permission vocabulary, membership model, seed grants, relationship persistence or default policy.
A whole-project `null` grant is legitimate only when independently supplied by trusted policy; no administrator label,
selector, `_meta`, Query metadata, supplied Permission array, prior page or reference creates it.

The maintained flow below shows the new policy boundary and unchanged owners. Its source is this guide, maintained
with TASK-00089's implementation; the committed revision and canonical Work handoff bind it to the candidate.

```text
ordinary signed request -> request-local AccessControl provider
                                      |
Common Tool -> Adapter AgentToolAvailability (coarse direct Permissions)
      |                               |
      +------ configured QueryBus ----+---- direct Query / console / worker
                       |
        Common owning Query handler
                       |
        AgentExecutionReadAdmission
          read Permissions -> consumer AgentExecutionScope
                           -> separate reference Permissions
                       |
        Common ExecutionAccess (intersection, no new grants)
                       |
        Common owning scoped reader -> bounded safe Tool output
```

Register these actual Common handlers on the same configured QueryBus used by the real unmodified Tools:

| Tool | Common handler (`Application/Mcp/`) | Common reader contract |
| --- | --- | --- |
| `execution.summarize` | `Session/QueryHandler/SummarizeExecutionsHandler` | `Session/Service/ExecutionReader` |
| `execution.list` | `Session/QueryHandler/ListExecutionsHandler` | `Session/Service/ExecutionReader` |
| `execution.get` | `Session/QueryHandler/GetExecutionHandler` | `Session/Service/ExecutionReader` |
| `execution.phases` | `Phase/QueryHandler/ListExecutionPhasesHandler` | `Phase/Service/PhaseReader` |
| `execution.activity` | `Observation/QueryHandler/ListPhaseActivityHandler` | `Observation/Service/ActivityReader` |

For example, with imports from the namespaces above and a consumer-supplied `$scope`/`$executionReader`:

```php
$admission = new AgentExecutionReadAdmission(
    $provider,
    $signedRequest,
    $correlationId,
    $scope,
    [PermissionName::fromString('READ_EXECUTIONS')],
    [PermissionName::fromString('DISCLOSE_EXECUTION_REFERENCES')]
);
$handler = new SummarizeExecutionsHandler($admission, $executionReader);
$router->registerHandler($handler::queryRegistration(), $handler);
```

Bind all five handlers with the same admission; configure the real Common Tools with that bus and
[the mixed Tool catalog](agent-mcp-authorization.md). Protect direct paths at the owning handler, not only the Tool
invoker. A console/worker constructs its appropriate fresh trusted authority composition, not a retained HTTP
provider or caller-supplied principal. AccessControl imports no Common Adapter inside production Application logic.
Consumers supply actual persistence/readers; controlled tests use Common's DBAL SQLite adapters without adding
production SQL, readers, Query implementations, schemas or persistence dependencies to this library.

## Scope, projections and truthful evidence

Common intersects project/session/phase/activity constraints before protected selection and aggregation. Summary
and selected-execution statistics cover the **full permitted selection**, not just the requested page. v1.5.1 also
restricts activity-derived freshness/order/model projection. Explicit empty activity grants hide all activity
contributions; omitted/null activity restrictions mean unrestricted activity **inside** independently permitted
structural scope. Session/phase state and separately scoped detail evidence retain their own upstream meaning;
an activity restriction is not a producer-wide ban on independently admitted state facts.

Reference disclosure needs both configured direct Permissions and consumer `detailGrants`. A grant identifies
producer/full UUID, not an ID alone. Hidden activities reveal no references even if an unrelated reference is
allowed. Timeline output never contains body bytes. [Protected detail](agent-protected-detail.md) is independently
admitted, not subordinate to timeline Permission; its metadata/body grants remain separate. Retained references
can truthfully report partial/missing capture, not permanent availability or complete history.

Optional `activity.execution` is the actual tagged Common safe target/timing/result value. It grants nothing.
Null target/endpoints/elapsed, unknown results and partial usage remain explicit; a returned operation does not
prove business success or commit. No inferred duration, accounting normalization, capture producer or copied schema
is added. Consumers must supply safe values: bounded strings are not universal secret detection.

Common owns actual ordering, strict pagination, nullable concealment, failure categories and complete encoded
response bounds. Unknown or concealed selected executions/phases remain null; empty summary/list follows the
owning Query contract. Storage/corrupt-evidence faults remain failures, not fabricated empty success. Actual Tool
and responder composition keeps private causes behind fixed safe errors. Use the consumer's single redaction-aware
diagnostic boundary; do not log signed requests, protected bodies or raw policy/storage exceptions publicly.

## Lifetime and race limits

Share one `CurrentAgentPrincipalProvider` between availability and Query admissions **within one ordinary request**.
Resolution consumes one nonce; it does not replay it per Tool or Query. The resolved request retains its immutable
principal snapshot. Each later page/request gets a fresh provider, signed request/nonce and admission, observing
current Permissions and consumer relationships. Scope is consulted for each invocation, not cached by this helper.

Common requires `ExecutionAccess` to remain effective for the entire invocation. Consumers must establish how
authority and relationship scope participate in their real read/transaction lifetime, including every relevant
writer. This helper supplies no atomic snapshot, all-writer lock or revocation-through-read guarantee. A change
after the final check can race with disclosure. Live offset pagination permits repeats/skips under concurrent
changes; prior pages are not leases. TASK-00088 is not imposed on ordinary request reads and does not automatically
solve consumer storage/scope races. Repeated live disclosure/background continuation retains its own contract.

Inspection performs no business Command, event or write. Initial authentication's existing nonce transaction is
not an execution-read mutation. No webserver, database service, live runtime or browser is needed for package proof.

## Qualification and remaining obligations

TASK-00089's Unit tests isolate admission over in-memory real authority; integration tests run real Common
Tools/bus/handlers/DBAL readers over controlled SQLite scope/persistence. They prove the original one-of-two
aggregate regression, exact partial usage, empty activity restrictions, SQL exclusion before decoding, narrow
rows/models/references, typed facts, direct paths, fresh pages, safe faults/overflow, no body reads and unchanged
business state/events. Exact Common v1.5.1 minimum/ordinary locks and complete gate/coverage evidence belong to
canonical `.runs/handoffs/TASK-00089/work.md`. Work evidence is not independent Review/QA acceptance.

Actual consumer relationships, persistence/cryptography, transport/sinks, authority-writer concurrency and
invocation-stability qualification remain separate. No production adoption, complete capture, stable history,
release, merge or deployment is claimed.
