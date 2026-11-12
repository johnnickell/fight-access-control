# Agent current-subscriber inspection

TASK-00095 supplies optional inward `AgentSubscriberReadAdmission` and consumer `AgentSubscriberReadPolicy`
under `Application/AccessControl/Agent/Notice`. It consumes Common's actual `SubscriberReadAdmission` and
`SubscriberAccess`; it adds no Query, Tool, SQL, schema, store, lifecycle or automatic grant.

## Explicit composition

Use Common **^1.5.0**, first sufficient published source `06fb5cd3720af9cf8295198f74040d95b64c5155`.
Supplier acceptance/adoption provenance is in the [contract inventory](common-contracts.md); feature minimum and
ordinary dependency/runtime results belong to the canonical TASK-00095 Work handoff. Do not infer feature
qualification merely from a dependency constraint or use a development snapshot as a stable contract.

For each ordinary authenticated request, compose:

1. A fresh `CurrentAgentPrincipalProvider`, its trusted `SignedAgentRequest` and correlation ID.
2. `AgentSubscriberReadAdmission($provider, $request, $correlationId, $requirements, $policy)`, where requirements
   are a nonempty list of canonical `PermissionName` values. All requirements are conjunctive; malformed/empty
   configuration rejects before authentication. Permission names/grants remain consumer inputs, not package seeds.
3. Both Common `SummarizeSubscribersHandler` and `ListSubscribersHandler` with **that admission**, and the actual
   Common `DbalSubscriberReader` over the same tracking storage used by the
   [protected opening journey](agent-live-disclosure.md). Supply a truthful storage visibility label, not an
   installation-wide claim for worker-local storage.
4. Register those handlers in Common's configured QueryBus using their `queryRegistration()` declarations.
   Every direct/bus caller must use the protected composition. No optional global hook protects a separately
   constructed unguarded Common handler; consumers own registration and alternate-entry-point wiring.

The admission resolves ordinary request authority, checks independent direct inspection Permissions, then calls
`AgentSubscriberReadPolicy::accessFor(AuthenticatedAgentPrincipal)`. The consumer returns trusted
`SubscriberAccess::create($scopes, $registrationIds)`; `null` registration IDs allow the granted scopes, an empty
list or empty scopes denies all rows. Exact IDs restrict further; they never confer authority by possession.
The caller's `SubscriberSelection` is deliberately **not** passed to the policy as a grant source. Policy must
exclude an entire registration unless every identity, requested/accepted filter, scope, topic and Resource
reference can be disclosed. A mixed-scope row requires all its scopes. Scope limits are applied before matching,
full-selection counts and page projection, never a global summary followed by filtered rows.

Listen, retained notice read/recovery, publication, Tool/family availability and inspection are independent.
A consumer must configure genuinely independent grants, not reuse its listen requirement as an accidental
inspection grant. Subscriber labels, role labels, claimed identity, JSON-RPC IDs, intended recipients, recent
heartbeats and previous browsing never supply inspection authority. Trusted opening identity is consumer-owned;
multiple streams can share it, and reused protocol IDs do not collide with opaque technical registration IDs.

## Counts, pages and observation truth

- Summary `subscriptions` counts admitted complete registrations; `subscriber_ids` counts distinct admitted opening
  identity strings, not authenticated people or connections. State counts and oldest/latest observation timestamps
  cover the same full selection. Summary has fixed dimensions, not silently truncated distinct-value catalogs.
- List `total` covers all admitted matching registrations, not just the page. Rows include complete requested and
  accepted maps separately, opening selection and references, registration/subscriber identity and lifecycle times.
  Tracking owner tokens and credentials are not projected.
- `recently_observed` means the retained observation has not expired at this invocation's clock; it proves neither
  current entitlement, confirmed connectivity, delivery nor processing. Explicit close gives `disconnected` unless
  observation time is ahead of the query clock; expired/future observation is `stale_uncertain`, not inferred close.
- `scope` identifies configured storage visibility, one invocation clock, `authorized_retained_registrations`
  coverage and **live** consistency. It is not a global checkpoint, historical audience archive or frozen snapshot.
  Rows/totals may change during scans and between summary/pages. Actual committed cleanup between reader batches
  can remove unseen rows while already observed rows remain in this result. Missing rows prove no historical absence.
- Ordering supports only `opened_at` ASC/DESC, with bytewise registration-ID ties in the same direction (default
  DESC). Pages have at most 16 rows, positive page/per-page and offset at most 1,000,000. Out-of-range pages may be
  empty with a nonzero current total. No remembered browser state or access lease is retained.
- Common validates selections: up to 16 scopes, exact supported identity/filter/Resource/time fields, finite values,
  strict ordering and page bounds. Supported filters are `subscriber`, `topic`, `registration_id`, `state`,
  `requested_filter`, `accepted_filter`, `requested_resource`, `accepted_resource`, `opened_from`, `opened_until`.
  Opening intervals use inclusive from/exclusive until. Unknown role/policy selectors reject, not broaden access.
- Common bounds exact registration grants at 4,096, stored context at 65,536 bytes, scan at 8,192 examined rows in
  batches of 32 and encoded page at 262,144 bytes. Overflows fail explicitly rather than truncate a complete map
  or return partial statistics. A smaller valid page may fit when a larger one fails. Default tracking capacity
  remains Common-owned (256 active/4,096 retained), not a new AccessControl policy.

Queries do not heartbeat, renew, close, clean, mutate business state, commit a business transaction or publish an
event. Initial authentication has its separate nonce effect. An already resolved ordinary request retains its
principal snapshot and consumes one nonce, not one per row/page. The policy is consulted for each Query; later
requests require fresh providers and current authority/scope. Live emitters separately retain their existing
original-bound checks; inspection adds no blanket replay or send-spanning lock.

## Faults and one safe boundary

Missing inspection Permission throws a fixed `PermissionDenied` before consumer policy. Common's owning handlers
wrap admission/policy/storage/reader errors as `SubscriberReadFailed` with fixed public text
`Subscriber inspection unavailable.` Denied scope can truthfully yield zero without probing concealed storage;
an admitted storage failure is not an empty-success result or a confidently disconnected subscriber.

Reuse the consumer's selected Common safe reporting/presentation boundary once. Private exception chains remain
internal diagnostic material: never serialize exception objects/previous causes, raw filters, bodies, credentials
or concealed references. This integration installs no logger, renderer or competing catch-to-Boolean fallback.
A Query result is not automatically an MCP response or HTTP/JSend envelope; no subscriber inspection Tool is shipped.

## Package proof versus consumer qualification

Mirrored admission tests and real TASK-00093 protected opening fixtures exercise routed summary/list admission,
independent grants, whole-row/sentinel concealment, requested/accepted references, full counts/multiple pages,
shared identities/reused request IDs, changing scope and fresh-request revocation. Real Common DBAL SQLite tracking
covers clean disconnect and real native HTTP worker SIGKILL immediately after acknowledgment, followed by
controlled retained-row observation at persisted observation time, exact expiry and beyond expiry. A separate
committed two-connection forward-heartbeat test proves future observations are uncertain. Late renewal refusal,
cleanup/disappearance and committed two-connection interleaving between reader batches remain covered.
Complete-page overflow and invalid selections/order/page/grants reject; policy/storage faults stay explicit.
Tracking snapshots and nonce counts distinguish read effects from authentication/lifecycle effects.

The native worker uses a controlled trusted continuation handoff; ordinary inspection uses real signed-request
verification fixtures and real AccessControl repositories. The two-connection scan test deliberately schedules
cleanup after the first real DBAL batch; it is not a production scheduler or all-backend race certification.
The canonical Work handoff owns exact dependency locks/source/runtime, focused/full-gate/coverage results and
warnings. Independent Review and nonvisual behavioral QA remain separate obligations.

Consumers must qualify production policy/grants and complete-metadata eligibility, trustworthy caller/opening
bindings, actual storage scope/backend/isolation, clocks, process/transport lifetimes, all authority writers,
concurrent lifecycle/cleanup behavior, safe diagnostic sinks and configured entry points. Controlled package
fixtures do not qualify deployed storage, worker connectivity, browser receipt, adoption, release or deployment.
