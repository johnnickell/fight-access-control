# Integrating the current Agent credential contract

This is the **unreleased v0.5.0 target**, not a release receipt, consumer qualification or deployment permission.
Use the exact candidate's public contracts together; released versions are unchanged. The
[scenario/evidence inventory](agent-operation-evidence.md) separates package results, superseded requirements and
missing consumer proof. [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md) permits only the current
API and persisted model: no legacy Agent adoption, historical reader, conversion/backfill or old-signature stub.
Do not interpret this guide as permission to reset data.

## Integration order and ownership

1. **Qualify one current composition before enablement.** Consumers own persistence/schema, authenticated context,
   authorization policy, keys, protected sinks, scheduling, storage admission and deployment tools. Package Domain
   owns transitions; Application services own transactions and recovery coordination. There is no production Adapter
   layer, consumer Permission catalog, enrollment service or broker here. Bind the exact candidate and its test suites
   as described in [issuance conformance](agent-issuance-conformance.md#run-and-bind) and
   [delivery conformance](agent-delivery-conformance.md#run-and-bind), not an older installed package.
2. **Inventory and fence every writer.** Repositories, audit and transactional authorization share the package-owned
   `TransactionalUnitOfWork` connection. It must reject nested transactions, roll back callback failures, never
   silently retry a callback and return only after confirmed commit. Every direct repository, worker, CLI and
   administrative writer participates; an HTTP allow or startup check is insufficient. Inventory caller/delegation,
   Permission/Role/User policy, destination/slot, lifecycle, key-reference and cohort writers using the
   [complete fence inventory](agent-operation-cohorts.md#fence-and-public-path-inventory). Acquire the cohort fence
   before subordinate authority/Agent/operation/slot/key fences and hold it through commit/rollback.
3. **Validate persisted contract and trusted readiness.** Both repositories implement
   `getOperationContract(): AgentOperationContract`. Its required arguments are `storageVersion = 1`,
   `canonicalVersion = 2`, `destinationVersion = 1`, positive monotonic `generation`, actually qualified
   `capabilities`, and explicit nullable `reconciledGeneration`. These are validated observations, not constructor
   defaults to fabricate absent storage. The witness must attest the exact active storage incarnation and reconciled
   history from outside the restorable dataset. Missing/mismatched evidence denies unsafe effects. Equal generations
   do not prove shared connections. The [cohort contract](agent-operation-cohorts.md) defines capability qualification;
   the [restoration contract](agent-restoration-safety.md) defines witness provenance. Consumers must exclude unsupported
   or unchecked writers at storage/trusted admission, including restarted binaries. This is not mixed-version support.
4. **Prepare keys and the fixed sink.** Independently encrypted authentication envelopes and delivery copies have
   different lifetimes. Authenticate every field of `AgentIssuance::toArray()` as delivery associated data.
   Never use authentication encryption as a delivery fallback. Qualify actual key access, authenticated decryption,
   rewrapping and key-version write/reference fences. The sink must support immutable exact-tuple staging, globally
   unique delivery-ID idempotency, verified opaque receipts, permanent deduplication tombstones and nondecreasing
   stable-slot order. A generic secret-store put or echoed receipt is insufficient. Check actual participants, not
   merely interface names or an asserted capability list.
5. **Enable bounded scheduled recovery.** Authenticate the worker as itself with explicit current delegation to
   original scopes/destinations. Schedule `AgentDeliveryRecoveryService::recover(scope, destination)` and use
   `nextRunAt()` for the next pass, including after a failed pass. It selects once and calls actual delivery at most
   once per selected item; it neither sleeps nor schedules itself. Events may accelerate dispatch but cannot replace
   scheduled discovery. Selection excludes obsolete slot reservations **before** limiting, across all scopes and
   binding revisions. Maintenance uses separate keyset pages including obsolete copies. Do not copy lifecycle policy
   into a consumer queue or create an unbounded refill loop.
6. **Enable activation/use only after their own qualification.** A claim reserves work, not permission to decrypt.
   A confirmed admission transaction permits a bounded fixed-sink invocation outside every database transaction.
   A separate current-authority transaction acknowledges the exact receipt. Already-admitted late bytes may remain
   inert; no database rollback recalls them. Consumer activation needs confirmed exact delivery and fresh fenced
   enrollment authority. Every later broker/use request checks current exact credential/destination and use authority
   again. Neither authentication, issuance, a receipt nor a once-valid activation allow grants continuing launch access.

Unsupported composition remains unavailable. Routine operation/recovery uses finite defaults without extra human
approval or mandatory manual settings; exceptional reconciliation still needs the consumer's operational authority.
Initial readiness requires affirmative external-effect inventory, not assuming empty local storage means no history.

## Public composition map

Types below are the current PHP contracts, not suggested wrappers. Agent services live under
`Application\AccessControl\Agent\Security`; consumer ports under `Application\AccessControl\Agent\Service`;
Agent repositories, requests and policies under the matching Domain aggregate. `Clock` belongs to Application Timing,
`TransactionalUnitOfWork` and `EventDispatcher` to Fight Common. Use the linked guides for full signatures and message
round trips. Serializable Queries use their handlers' Common registrations; secret-bearing work is synchronous.

| Use case / public entry | Injected composition and outcome |
| --- | --- |
| [Provision](agent-provisioning-operations.md): `AgentProvisioningService::provision(AgentOperationKey, AgentProvisioningRequest)` | Agent/operation/audit repositories, `AgentOperationAuthorization`, `HmacSharedSecretGenerator`, `HmacSharedSecretCipher`, `AgentDeliveryCipher`, Clock, UoW, EventDispatcher, optional `AgentOperationLimits`. Safe `AgentProvisioningResult`. |
| [Rotate](agent-rotation-operations.md): `AgentCredentialRotationService::rotate(AgentOperationKey, AgentRotationRequest)` | Same capabilities; mandatory `authorizeRotation()` fences current scope, original target and destination. Safe `AgentCredentialRotationResult`; predecessor cancellation, successor operation and audit commit together. |
| [Revoke](agent-credential-retirement.md): `AgentCredentialLifecycleService::revoke(actorId, agentId)` | Agent repository, audit, Clock, UoW, EventDispatcher. Consumers protect every entry point; actor ID is audit provenance only. Repository replacement atomically retires original delivery without key/sink access. |
| [Status](agent-operation-status.md): `GetAgentOperationHandler` | Operation repository and `AgentOperationAuthorization::authorizeRead()`, before lookup and again with original target before disclosure. No UoW, material access, audit, event or new-work capacity. Safe `AgentOperationView`. |
| [Deliver](agent-credential-delivery.md): `AgentCredentialDeliveryService::deliver(key, destination, deliveryId)` | Agent/operation repositories, scope and `AgentDeliveryAuthorization`, `AgentDeliveryDecipher`, `AgentCredentialSink`, Clock, UoW, optional `AgentDeliveryPolicy`. Safe `AgentDeliveryResult`; claim, admission and outcome are separate transactions. Optional `AgentCredentialReceiptLookup` avoids unnecessary materialization. |
| [Discover/recover](agent-delivery-recovery.md): `ListDueAgentDeliveriesHandler`, `AgentDeliveryRecoveryService` | Query: operation repository, delivery authorization and Clock. Scheduler: that handler, actual delivery service, Clock, optional `AgentDeliverySchedule`. Selection is read-only and never grants admission. |
| [Maintain](agent-delivery-maintenance.md): `AgentDeliveryMaintenanceService::rewrap/expire/cleanup` | Operation repository, `AgentMaintenanceAuthorization`, `AgentDeliveryRewrapper`, `AgentCredentialCleanup`, Clock, UoW, optional `AgentMaintenancePolicy`. Original key/destination/delivery ID, plus target key version for rewrap. Safe `AgentMaintenanceResult`. Rewrap is inside a bounded fenced transaction; cleanup invocation is outside transactions between admission and acknowledgement. |
| [Maintenance reads](agent-delivery-maintenance.md): `ListAgentDeliveryMaintenanceHandler`, `CountAgentDeliveryKeyReferencesHandler` | Operation repository, maintenance authorization and Clock. Secret-free keyset pages and global diagnostic reference counts; no material/claim/commit/events. Zero is not permission to destroy keys. |

Repositories persist the complete current operation: canonical request/marker, original issuance and dispositions,
state revision, protected copy/key version, pinned policy, attempt token/fence/lease/admission, retry/failure history,
receipt and `sinkCleaned`. Hydration supplies recorded state, not defaults that reset completed work. Scoped keys and
global delivery IDs are unique. Agent credential revision starts at **0**; destination revisions/write order and
Permission-assignment revisions are positive. [Validated Agent hydration](agent-current-contract.md) never generates
issuance. Every lifecycle replacement validates the exact successor and original-operation correlation, including
non-HTTP/direct writes. No current path may bypass cancellation because an old recovery marker is absent.

Concrete sensitive parameters and UoW callbacks must be redacted; interface attributes are not inherited. Qualify
actual logging/exception adapters with argument capture enabled, following the
[retirement trace contract](agent-credential-retirement.md#composition-and-atomic-cancellation). Never log provider
throwables, ciphertext, invocation bytes, key paths or claim tokens. PHP does not promise physical memory erasure.

## Retained keys and outcome handling

Persist the original key **and request before calling** provision/rotation. Derive scope from trusted authenticated
context and destination from server registration, never a caller URL/path. Same-key resolution requires fresh
current authority, retains the original rotation predecessor and does not repeat generation, mutation, audit or
issuance publication. A genuinely new rotation is a separately authorized operation, not an automatic retry fallback.

The [single canonical contract](agent-canonical-upgrades.md) uses no-argument request `canonicalize()`, exact ordered
JSON and marker **2**. Provision names trim the fixed Unicode edge set, preserve case/internal whitespace and do not
normalize NFC. Rotation binds the original target/predecessor/destination. Retain exact request and issuance through
completion, supersession, revocation, expiry and cleanup. Unsupported markers (including 1) reject without a reader,
rewrite, conversion or new key. Material expiry never expires deduplication: permanent operation/sink tombstones and
slot order survive. Safe status absence is indeterminate, not proof of rollback or permission to issue another key.

| Observation | Required response |
| --- | --- |
| Pre-commit issuance failure | Sanitized rejection; atomic rollback. Keep original key/request for an authorized retry, not guessed committed success. |
| Callback completed but commit did not confirm | Safe indeterminate issuance/delivery/maintenance result. Recompose if UoW closed, then resolve authoritative original state; never infer rollback from an exception or missing event. |
| Confirmed new provision/rotation; publisher throws (even both publishers) | Confirmed safe metadata with typed `AgentPublicationWarning::PUBLICATION_FAILED`. Durable pending delivery survives; scheduler recovery needs neither event nor caller retry. Warning is transient, not stored delivery status. |
| Confirmed issuance only | Not delivery, enrollment activation or launch/use. No raw-secret result or general secret-read capability exists. |
| Receipt-confirmed delivery | Delivery copy removed; verify original exact receipt on recovery. Lost delivered material returns `RECONCILIATION_REQUIRED`, never envelope fallback or automatic rotation. |
| Revocation publication throws | Durable retirement remains; original publication fault still rethrows after attempted redacted failure publication. The D3 warning exception applies **only** to replacement provision/rotation, not revocation, AuthenticationService or other handlers. |

There is no outbox or automatic notification retry. Same-key service retries publish no second success fact. Consumer
notification retry, if added, must retain the original fact identity and document duplicates. Receipt-aware recovery
can avoid repeat staging; without lookup it repeats original bytes/ID only after a fresh confirmed admission. Both
are at-least-once, not exactly-once disclosure. Recovered admission alone permits receipt reconciliation only.

## Finite defaults and validated overrides

These values come from current `AgentOperationLimits`, `AgentDeliveryPolicy`, `AgentDeliverySchedule` and
`AgentMaintenancePolicy`, not illustrative configuration. All bounds are inclusive; invalid constructor combinations
reject with `INVALID_REQUEST` rather than disabling limits. Omit overrides to use defaults.

| Setting | Default | Valid override / relationship |
| --- | --- | --- |
| New provisioning raw name bytes | 512 | 128–4096; request ingress always at most 4096 bytes, normalized name nonempty UTF-8 and at most 120 code points |
| Pending copies per originating scope | 100 | 1–10000 |
| Pending copies repository-wide | 10000 | 1–1000000 and at least per-scope limit |
| Delivery lease | 60 seconds | 1–3600 |
| Admission duration | 15 seconds | 1 through lease |
| Retry delay | 30 seconds | 1 through retention |
| Original delivery retention | 86400 seconds | At least lease, at most 604800 |
| Attempts | 100 | 1–1000 |
| Discovery/recovery batch | 50 | 1–100, one selection/pass; current exact slot reservation may yield fewer |
| Poll interval | 30 seconds | 1–3600; consumer schedules, service does not sleep |
| Maintenance page | 50 | 1–100; consumer processes one page/pass and retains exclusive delivery-ID cursor |
| Inert-entry cleanup grace | 86400 seconds | 1–604800 after original delivery retention, and only eligible terminal state |

Examples actually exercised in conformance: delivery `(10, 3, 4, 20, 2)`, schedule `(1, 90)`, maintenance `(1, 5)`;
zero/excess batch, admission longer than lease, retention shorter than lease, retry beyond retention and zero/excess
attempts reject. The [D4 evidence row](agent-operation-evidence.md#ratified-additions) also maps Domain boundary tests.
First claim pins policy; rewrap before first claim pins the same default retention. Restart/new configuration cannot
extend an operation's lifetime. Admission uses the earliest policy/lease/authority/original-retention deadline;
retries wait for both lease and retry time. No override weakens immediate lifecycle cancellation.

Capacity gives **new work** retryable `CAPACITY` with rollback. Existing-key resolution precedes new-work limits;
status/discovery/delivery/maintenance do not consume new issuance capacity. Rotation counts after atomic predecessor
cancellation, but rolls it back if an unrelated full queue rejects the successor. Retired material frees pending-copy
capacity, never its retained key. Outage is unavailable/indeterminate, not empty work or zero key references.

Maintenance includes obsolete reservations excluded by delivery discovery. It preserves correlation, receipts and
high-water/tombstone evidence. Current delivered credentials are never cleanup-eligible. Closing key-version write
admission and an authoritative fenced recount are necessary but insufficient for physical key retirement: separately
account for in-flight uses, authentication envelopes, backups and all other consumers. Physical deletion has no
package command and cannot be promised during storage, scheduling or authorization outages.

## Restoration and consumer evidence handoff

Prefer forward repair. For controlled same-contract restoration, close independent admission **before** replacing
storage, quiesce all writers and drain/fence admitted calls. Preserve independent original operation/audit history,
receipts, tombstones, destination high-water and authority/key state. Reconcile exact bindings for the live storage
incarnation under exclusion; missing, uncertain or mismatched evidence means hold/unavailable, not guessed absence.
Advance generation and attest it only after verification, then requalify and resume narrowly. Never reset order or
reuse a generation to avoid stale-acknowledgement rejection. Full abort/resume requirements and mandatory real-tool
rehearsals are in [restoration safety](agent-restoration-safety.md#consumer-owned-stop-reconcile-and-resume-procedure).
The package cannot detect arbitrary physical rollback, authenticate an adapter's witness or stop a bypassing binary.

The [consumer gap register](agent-operation-evidence.md#consumer-gap-register) is the handoff checklist. Before any
consumer support claim, its owner must supply the exact package/adapter/runtime identities, real writer inventory,
commands/tool versions, times/exits, isolated connection/process schedules, independent persisted before/after
observations and sanitized results. Run the consumer-bindable suites **and** actual activation/use and controlled
restore rehearsals. Do not replace failed/missing runs with package fixtures or tests of Markdown/schema text.
Release certification, consumer adoption and deployment each require separate authority. Agent OS TASK-00138 and
its other prerequisites are not closed by this guide.
