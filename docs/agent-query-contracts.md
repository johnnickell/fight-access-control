# Agent operation Query contracts (Unreleased)

TASK-00134 tightens the four current read paths without changing their message or public status wire shapes.
These are breaking pre-v1 PHP read-adapter/failure changes, not a release or consumer qualification.

| Query | Exact handler result | Deliberate absence / observation |
| --- | --- | --- |
| `GetAgentOperation` | `AgentOperationView` | Authoritative absence is `indeterminate`, never rollback, rejection or permission to issue a new key. |
| `ListDueAgentDeliveries` | `list<AgentOperationView>` | Empty eligible work is valid. Original scope, exact destination, current credential/reservation, due time, order and limit must agree. |
| `ListAgentDeliveryMaintenance` | `list<AgentOperationView>` | Empty/final pages are valid. Exact historical binding, requested material/cleanup predicate, policy, exclusive cursor and bytewise order apply. |
| `CountAgentDeliveryKeyReferences` | Nonnegative `int` | Complete global retained-copy observation, including obsolete/unsupported records; zero never authorizes key retirement. |

## Narrow repository composition

Readers still receive only `AgentCredentialOperationReadRepository`, never its independent material-bearing write
interface or a Unit of Work. `getStatusByKey()` remains nullable. Both work methods now return
`list<AgentDeliveryWorkView>` rather than bare status Views. Each item carries the original safe operation View,
retained-copy presence (not material), next due time, original pinned retention deadline, sink cleanup acknowledgement,
and nullable current stable-slot reservation. Construct it directly from **one coherent authoritative metadata
snapshot**. For due selection the reservation is required and must equal the original issuance reservation across
all scopes/bindings. Maintenance may omit it because obsolete reservations are intentionally included.

`AgentDeliveryWorkView::fromOperation()` maps an already-loaded snapshot for modeled adapters/tests; real read
adapters project metadata directly without loading roots, ciphertext, attempts, receipt bodies or keys. Selection
facts stay inside repository/handler composition: handlers return only `AgentOperationView`, not the selection DTO.
No status/OpenAPI field or secret-read handle is added. Update adapters directly; no bare-View compatibility mode.

Handlers validate native-array element/list contracts and request-specific facts, rejecting excessive/malformed
batches instead of sorting, truncating or normalizing them. Discovery compares against its fixed selection time;
fresh authorization checks retain their own current decision time. Cleanup checks the requested grace against
original pinned retention, not the worker's current delivery policy. Repository completeness, actual durable
reservation/retention truth and indexed selection remain persistence obligations; a DTO cannot prove an adapter's
honesty, SQL isolation or cross-read atomicity. Count completeness cannot be inferred from a work page.

## Denial versus internal failure

Authorization still runs before discovery and after reads (even absence/empty/zero), plus each original target
before batch disclosure. No partial unauthorized batch is returned. Unsupported markers are rejected after current
target authorization, without recanonicalizing history. A key-only status lookup can legitimately find an operation
whose destination differs from the caller's requested binding: it remains concealed with `UNAUTHORIZED`.
A wrong returned key, foreign filtered batch, inconsistent selection predicate/order/cursor or negative count instead
violates the repository promise and raises Application `AgentOperationReadFailed`, a Common `ServerError` subtype.

For `GetAgentOperation`, deliberate read denial now becomes Common Application `PermissionDenied`; explicit
unavailability and an authorized View's unsupported marker become `ServiceUnavailable`. Other Query/worker paths
retain their existing Domain reason contracts. Status validates the returned key before using its target for a
second authorization check; a wrong-key promise remains internal even when that foreign target would be denied.
A valid same-key View's marker is checked only after current target authorization. A failed repository read cannot bypass that ordering by reporting a record marker.
Invalid decoded rows and write-only business reasons (capacity/conflict/contention) at a read boundary are internal
faults, not caller input errors or a new Query admission policy.
Unknown infrastructure/programmer/result faults raise `AgentOperationReadFailed`, not fabricated expected
unavailability, not-found, empty success or zero. It contains fixed text and no previous throwable/provider data.
Do not parse messages/codes to infer intent. Consumers deliberately map existing operation reasons at their own
presentation boundary; Common's safe HTTP policy classifies the new internal failure as 500 with its public constant
projection. Broader HTTP/MCP presentation and generic bus inference retain their separate TASK owners.

Queries perform no mutation, transaction/commit, audit/Event, capacity admission, claim, cleanup or decryption.
Status, selection facts and counts are snapshots, never grants or live leases for delivery, activation, use or
key retirement. Original keys, confirmed/indeterminate issuance, retained history, canonical marker 2, current
cohort/restoration checks and original-key recovery remain unchanged.

## Proof boundary

The default tests include real configured Common routing for all four paths, adversarial projections, safe-error
composition, retained/indeterminate history, authorization/concealment and read-only observations. Static fixtures
assert exact handler/repository results and reject incompatible collection/count uses; they do not claim generic
QueryBus inference. Supported-minimum/ordinary dependency and canonical gate evidence belong in the TASK Work
handoff. Independent Review/nonvisual QA and actual consumer persistence/authorization/key/sink qualification remain
separate. See [status](agent-operation-status.md), [recovery](agent-delivery-recovery.md),
[maintenance](agent-delivery-maintenance.md) and [integration](agent-integration.md).
