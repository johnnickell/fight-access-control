# Typed audit evidence (Unreleased)

TASK-00122 replaces the pre-v1 evidence and persisted contract directly across existing writers and their
current callers, fixtures and schemas. The TASK and canonical Work handoff own package verification evidence.
No release, consumer adoption, independent acceptance or expanded audit coverage is claimed by this document.

## Current construction and persistence

`AuditEvidence::record()` requires a direct `UserId|AgentId|SystemId|AnonymousId`, `AuditSubject`, `AuditAction`,
trusted outcome `DateTimeImmutable`, admitted facts and explicit nullable `AuditCorrelation`. It generates a
non-nil `AuditEvidenceId`. `fromArray()` restores that identity rather than generating another record.
There are no actor-string factories, old-shape readers or arbitrary context fields.

Persist exactly `id`, `actor`, `subject`, `action`, `occurred_at`, `correlation` and `facts`. Actor and subject
representations contain both `kind` and `id`; equal UUID text never erases kind. `AuditActorKind` is only the
finite discriminator codec, not an actor wrapper, principal resolver or authorization service. A caller label
is not necessarily a UUID. Commands carrying typed provenance serialize `actor_kind` alongside `actor_id`;
missing kinds and labels reject rather than selecting a fallback actor.

Time preserves microseconds and offset in `Y-m-d\TH:i:s.uP` form. Writers supply their actual outcome clock sample,
not envelope creation or insertion time. Correlation holds the incoming `MessageId` and/or complete retained
`AgentOperationKey`, including namespace/caller scope. Synchronous paths without either use null. Never invent
correlation; record identity/correlation is neither authority nor universal deduplication.

## Admitted facts

- `AuditUserOutcome` captures actual before/after `AuditUserRevisions` and applicable typed grant relationships.
  These snapshots contain lifecycle and authoritative counters, not email addresses, hashes or entities.
  Creation has no predecessor and retains initial counters (including the actual default-Role assignments for
  registration). Each supported action validates its lifecycle transition, exact one-step affected counters and
  unchanged unaffected counters. Same-address pending-registration recovery may leave User counters unchanged;
  enable/restore do not invent security-counter increments. Soft deletion retains an actual successor, not
  fictitious physical removal.
- `AuditGrantOutcome` captures actual grant revisions/delivery identities without implying a User mutation.
  Delivery facts carry the actual persisted `CredentialDeliveryStatus` and bounded failure classification, not provider
  diagnostics. Permanent provider failure pairs with permanent-failure state; retryable/unexpected failure pairs
  with retry-pending state. Each grant reference retains its family; a replacement can identify different predecessor and
  successor grants/deliveries without fabricating an increment across their identities. User and grant outcomes
  additionally retain `replaced_predecessor`: the old generation's actual post-cancellation revision, or its
  unchanged terminal revision when appending after terminal history. It is required when replacing a different
  grant identity and absent on initial issuance/in-place outcomes. The original, retained predecessor and new
  successor are separate facts, not a fictional before/after revision of one grant. An in-place outcome preserves
  its delivery identity and advances the grant revision exactly once without overflow. New-generation issuance
  starts at revision zero with a distinct delivery; delivery confirmation/failure and retry cannot replace a
  generation. Initial issuance also starts at zero when there is no predecessor. Consumption/cancellation requires
  both operands of the same exact generation, never a fresh or replaced grant. Dangling grant predecessors reject.

  | Existing action | Admitted grant relationship |
  | --- | --- |
  | Invite/register User | Initial activation issuance, no grant predecessor |
  | Correct invitation/recover registration | Replacement activation issuance; retained predecessor advances once by cancellation |
  | Restore User | Initial activation issuance only for pending activation; active restoration has no grant facts |
  | Enable/disable/delete User; change password | No grant facts (incidental lifecycle cancellation is not this outcome's relationship) |
  | Complete password reset | Exact reset generation consumed, advancing once |
  | Request email change | Initial email-change issuance or replacement retaining the unchanged terminal predecessor |
  | Cancel/confirm email change | Exact email-change generation cancelled/consumed, advancing once |
  | Resend invitation | Replacement activation issuance; retained predecessor advances once by cancellation |
  | Request password reset | Initial reset issuance or replacement retaining the cancelled/unchanged terminal predecessor |
  | Delivery confirmation/failure; invitation retry | Exact grant/delivery generation, advancing once; no replacement |

- `AuditAgentOutcome` captures credential identities/revisions/lifecycle and the actual issuance delivery ID.
  Provisioning has no predecessor and starts ACTIVE at credential revision zero; rotation advances exactly once
  from an ACTIVE predecessor to a distinct ACTIVE credential. Revocation retains the same credential identity and
  revision while changing ACTIVE to REVOKED. Noncreation outcomes retain arbitrary authoritative existing revisions,
  including terminal revision limits where no increment is needed. Issuance requires its real scoped operation correlation.
- `AuditSessionRevocation` admits only session identity and actual before/after revisions. Business-required
  free-text reasons still undergo their existing validation, but are deliberately omitted from durable evidence.
  Neither string typing nor a regular expression can establish that arbitrary reason text is secret-free.
- `AuditRoleRevisions` preserves the complete incarnation/sequence with explicit absent creation/removal state.
  Creation requires sequence one of the supplied incarnation, without generating or replacing it. Changes retain
  that incarnation and advance exactly once; removal retains any authoritative predecessor and no successor.
  `AuditRolePermissionOutcome` additionally admits the related `PermissionId` for grant/revoke actions.
- `AuditFeatureOutcome` retains actual positive before/after revisions, availability status and related testing
  Permission; creation requires OFF at revision one, and creation/removal explicitly omit nonexistent state.
  Status and Permission changes are distinct actions.
- `AuditPermissionOutcome` admits canonical name, tier and managed ownership before/after an actual definition change,
  with explicit absent creation/removal state. Reconciliation retains managed ownership in both states; it cannot
  claim a custom Permission or demote managed ownership. Permissions have no package revision: none is fabricated.

These shared construction/hydration contracts do not insert audit into currently unaudited Role, Feature or
Permission handlers. Family wiring and expanded outcome coverage remain separate.

`AuditSubject` admits only the supported User, Agent, Role, Permission and Feature identities. Action/facts must
also support that subject; accepting an identity alone does not add a new outcome or audit-writing path.
Further family outcomes and their action-specific facts remain separately owned implementation work.

No facts type accepts credentials, tokens, hashes, ciphertext, raw messages/entities, exceptions or arbitrary
text/context. Evidence and its identifiers are nevertheless not automatically authorized public response/log data.

## Provenance versus authority

Existing business authorization remains mandatory and independent. Administrative User IDs retain their policy
checks. Registration and reset-request paths deliberately establish `AnonymousId`; this is declared public-entry
semantics, never missing-data recovery. Password reset completion and email confirmation now accept explicit
nullable typed provenance; with their current mandatory audit wiring, missing provenance rejects generically after
credential admission and before the first mutation. The target User ID alone does not prove an authenticated actor.

`AgentOperationAuthorization` authenticates the real invoker and fences current caller/delegation/target/destination
policy, then returns explicitly established nullable typed **originating** provenance. Null is explicit absence,
not a fallback; a new enabled mutation rejects it before mutation, while original-key resolution needs no new
audit-only actor. A delegated worker stays itself for
authorization and preserves the originating User/Agent for audit. Do not parse the scoped caller label, rewrite the
scope or use a supplied audit identity as an authorization grant. Consumer implementations must update this breaking
port contract; no actual adapter or shared-connection qualification follows from package fixtures.

`AgentCredentialLifecycleFailed` now preserves typed provenance as `actor_kind` plus `actor_id`. Both are explicitly
null when no origin has been admitted; this coarse failure notification is not committed mutation evidence and
cannot fabricate an actor. The synchronous revocation owner preserves its supplied typed actor even on failure.

## Atomicity and staged optionality

Keep `AuditEvidenceRepository::add()` inside the existing owning mutation transaction on the same connection.
Configured audit failure aborts staged work; it never means auditing is disabled. Concurrent losers roll back their
evidence. Uncertain commit is not rollback proof. Retained-key resolution adds no duplicate evidence and cannot
backfill history. External delivery effects, separately committed claims and ordinary post-commit propagation retain
their own truthful outcomes. Agent provision/rotation alone retain their existing confirmed-commit publication policy;
void Commands do not return or reconstruct publication warnings.

The approved family composition is a **last nullable repository dependency**: omitted/null intentionally disables
audit; no enable flag or no-op repository is required. Enabled evidence needs provenance before its first required
mutation, while pure no-change/disabled execution gains no new audit-only actor obligation. TASK-00122 defines this
contract; TICKET-00044 through TICKET-00047 own actual optional constructor wiring, family admission and expanded
outcomes. Existing mandatory constructors have not become optional merely because the shared contract changed.
TICKET-00048 retains cross-family conformance and real consumer qualification. Consumers still own persistence,
access/retention, schema/deployment and actual connection/race/restore qualification; no backfill is provided.
