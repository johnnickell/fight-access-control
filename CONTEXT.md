# Fight AccessControl Context

## Purpose

Fight AccessControl owns framework-neutral identity, credential, session, authorization, and account-lifecycle
behavior shared by Fight applications. The repository-local behavioral and security authority is
[TICKET-00001](planning/tickets/00001-TICKET.md).

## Vocabulary

- **User**: the stable human identity whose canonical email remains unique across pending, active, disabled, and
  deleted states.
- **Grant**: a purpose-bound, hashed, expiring, single-use credential for activation, password reset, or email
  change. Reissue revokes its predecessor.
- **Recoverable credential delivery**: package-owned activation, password-reset, or email-change work whose encrypted
  material commits with its grant. Workers discover secret-free due state, commit an exact leased claim, invoke a
  provider outside every transaction with the stable delivery-generation ID, then commit one typed expected-state
  outcome. Expired leases may repeat invocation, so the guarantee is at-least-once rather than exactly-once.
- **Refresh session**: authoritative server-side session state owning credential rotation, revocation, activity,
  lifetime, authentication version, and coarse device information.
- **Authenticated principal**: an immutable framework-neutral identity and authorization snapshot revalidated
  against authoritative storage once per request.
- **Agent**: a machine principal with direct Permission authority; it is not a User, refresh session, framework
  security user, or AI persona.
- **Agent credential**: one active HMAC authority belonging to an Agent. It has a public credential ID and a
  consumer-encrypted shared secret; the raw secret exists only when issued or during authentication verification.
- **Agent credential revision**: the monotonically advancing version of an Agent's single credential authority.
  Rotation replaces the active credential at a new revision; revocation is terminal and removes authentication
  authority.
- **Agent direct Permission assignment**: an Agent-owned, duplicate-free set of stable Permission identities; it
  grants direct authority without introducing Agent Roles or a policy engine. A Permission cannot be removed while
  any Agent has it assigned.
- **Agent Permission-assignment revision**: the monotonically advancing version of an Agent's direct Permission
  assignment. It advances only when that set changes and is independent of the Agent credential revision.
- **Agent read result**: an immutable, secret-free record of an Agent's ID, lifecycle state, credential ID and
  revision, assigned Permissions by ID and canonical name, and Permission-assignment revision. It does not decide
  whether an action is allowed.
- **Authenticated Agent principal**: an immutable authoritative Agent identity and direct-Permission snapshot,
  resolved as one authentication flow rather than from an `AgentView` or a follow-up query.
- **Current Agent principal provider**: a consumer-composed, request-scoped module that authenticates one signed
  Agent request and returns its cached immutable Authenticated Agent principal for that request. Authentication,
  authority revalidation, Permission snapshot resolution, safe diagnostics, and request caching form one flow.
- **Agent-aware MCP tool availability**: an AccessControl-owned, request-scoped decision that maps a Fight Common
  canonical tool name to static required-Permission metadata and checks it against one current Authenticated Agent
  principal snapshot. Common receives only available or unavailable; later MCP requests resolve current authority
  again, and unavailable and unknown tools remain publicly indistinguishable.
- **Security context**: one request-specific, consumer-selected Authenticated User or Authenticated Agent authority.
  It is constructed with exactly that one authority and provides the common Permission and Role checks used by
  consuming code. Consumers select the authentication path through their framework adapters; the package does not
  inspect transports or choose between User and Agent authentication. Every authenticated authority exposes whether
  it is a User or Agent through the package-owned authenticated-principal type. Authenticated Agents have direct
  Permissions and no package-level Roles.
- **Principal Permission**: the narrow immutable Permission identity and canonical name captured in either an
  Authenticated User or Authenticated Agent snapshot. It is authorization data, not the authoritative Permission
  aggregate or the richer administrative Permission view.
- **Desired authorization state**: assigning or granting authority that is already present, or removing or revoking
  authority that is already absent, succeeds as an idempotent no-op. A no-op does not write persistence, advance an
  authority revision, or publish a success event. Missing identities, invalid definitions, stale expected revisions,
  and violated invariants still fail hard. Complete-set inputs are normalized as sets, so repeated identities in one
  request do not change the desired state or cause a failure.
- **Agent authentication diagnostic**: a secret-free, server-observable classification and correlation identifier
  for a failed Agent authentication. It is not disclosed to an untrusted caller.
- **Signed Agent request**: the transport-neutral representation of the Fight Common HMAC v1 canonical request;
  consumer applications map transport data into and out of this representation.
- **Managed Permission and Managed Role**: stable version-controlled authorization definitions reconciled
  exactly and atomically.
- **Super Admin Role**: the uniquely authoritative managed Role named exactly `ROLE_SUPER_ADMIN`; its name is
  reserved and cannot be used by a custom Role. Only this Role may hold protected Permissions. User Role assignment
  and removal use ordinary revision and reference semantics, including pending-User bootstrap; application builders
  authorize all caller entry points and protect last-admin removal.
- **Permission tier**: the non-null classification of a Permission. `ADMIN_SAFE` makes it eligible for delegation
  through consumer-protected command entry points; `SUPER_ADMIN_ONLY` reserves its authority for the designated managed
  Role and human Users.
- **Custom Permission**: a runtime-owned Permission classified `ADMIN_SAFE` from creation. It cannot carry the
  protected tier; delegation still requires authority over the actor and target.
- **Protected managed Permission**: a managed Permission the consuming project classifies `SUPER_ADMIN_ONLY`. The
  accepted v0.4.0 policy reserves its membership for managed `ROLE_SUPER_ADMIN` and its authority for human Users
  through that Role; policy definitions and promotion reject forbidden Role and Agent membership. Consumers fence
  promotion and grants through their persistence adapters.
- **Conformance suite**: reusable tests of observable Domain and Application outcomes which consumer repositories
  bind to their own adapters.
- **OpenAPI schema component**: an opt-in reusable description of a package-owned payload that a consumer's OpenAPI
  generator scans and incorporates into its own document. It does not create an endpoint, HTTP response, or runtime
  transport contract. Its stable OpenAPI key begins with `Fight.AccessControl.` and does not need to match its PHP
  class name.
- **OpenAPI schema contract**: the stable name and validation shape of a published schema component. It is a public
  package API even though consumers choose where and how to reference it.
- **OpenAPI metadata distribution**: the package's non-autoloaded `openapi/` directory, containing schema-anchor
  attributes and a bootstrap file for a consumer-owned OpenAPI generator. It is neither Domain nor Application
  production code and does not define a package-owned document.
- **Browser authentication response**: the public authentication body containing access-token material and session
  metadata, but never a refresh credential. A consumer may deliver that credential through an `HttpOnly` cookie.
- **Portable token set response**: the authentication response for a non-browser client or an explicit body-token
  profile. It includes the sensitive opaque refresh credential as well as the browser-response fields.

## Package Boundary

Production dependencies flow `Domain <- Application`. Domain owns invariants and lifecycle state. Application
owns a synchronous secret-bearing authentication service, explicit non-sensitive commands and queries, portable
ports, immutable token/read results, and transaction orchestration. This package has no production Adapter layer and no
framework dependency. Consumers own clients, persistence, HTTP and cookie adapters, cryptographic keys, mail,
queues, realtime delivery, hosting, and runtime composition.

Application builders protect every command entry point using controls suited to HTTP, CLI, workers, or other
adapters. Consumer outer layers normally authenticate, validate, authorize, choose synchronous or asynchronous
invocation, and translate failures. Application and Domain code may therefore assume those outer checks succeeded
and fail hard when their own invariants are violated. For v0.4.0 planning, AccessControl retains its narrow
Application authorization ports for cross-user session access, email-change administration, and pending-invitation
correction, which concern ownership of a particular User's resource. The actor-only Role administration and User
Role-assignment and actor-only Agent Permission administration ports have been retired. Custom-Role grants and
direct Agent assignments require authoritative `ADMIN_SAFE` Permissions even for no-ops, fenced through their
respective repository writes. Consumer-managed caller Permissions and package-owned tier and managed-policy
invariants remain distinct.
Package-owned workflow coordinators are final implementation details marked `@internal`; consumers depend on the
public commands, services, authenticated principals, and Security context rather than implementing those coordinators.
Credential-delivery providers receive only one short-lived sensitive invocation after a committed claim and return a
typed outcome. Consumer schedulers use the package's secret-free due-work/status queries and direct delivery commands;
they do not copy claim, retry, terminalization, or stale-generation policy.

## Accepted replacement direction — not current runtime behavior

[EPIC-00009](planning/epics/00009-EPIC.md) records the separately planned breaking replacement for recoverable Agent
provisioning and rotation. John ratified D1–D4 and confirmed the complete destination and boundaries on 2026-09-27.
The approved TICKET decomposition assigns issuance/resolution to [TICKET-00012](planning/tickets/00012-TICKET.md),
protected delivery/recovery to [TICKET-00013](planning/tickets/00013-TICKET.md), and migration/compatibility plus
scenario traceability to [TICKET-00014](planning/tickets/00014-TICKET.md). TICKET-00012 now has approved
[TASK-00046](planning/tasks/00046-TASK.md) through [TASK-00049](planning/tasks/00049-TASK.md): provision, safe reads,
rotation and issuance conformance. TICKET-00013 now has approved [TASK-00050](planning/tasks/00050-TASK.md) through
[TASK-00054](planning/tasks/00054-TASK.md): retirement fences, protected delivery, restart recovery, material
maintenance and delivery/lifecycle conformance. TASK-00048/00049 now have concrete cross-TICKET prerequisites and
wait on unfinished dependencies rather than missing information. TICKET-00014 now has approved
[TASK-00055](planning/tasks/00055-TASK.md) through [TASK-00059](planning/tasks/00059-TASK.md): existing-Agent
compatibility, contract cohorts, canonical upgrades, restoration safety and migration/evidence guidance. All three
TICKET decompositions are complete; TASK-00046 remains first ready and the others wait on dependencies. Planning
does not qualify a consumer or authorize implementation, migration, release or adoption.
The replacement requires caller-scoped operation correlation, protected delivery and consumer authorization
participating in the package-owned transaction under shared authority fences. Consumers still own policy, adapters,
keys and the sink; unsupported integrations fail closed. Existing raw-return APIs remain the current implementation.

For those two replacement operations only, confirmed issuance commits return safe operation metadata with a typed,
sanitized publication warning if post-commit publication fails. Pre-commit failure and indeterminate commit are
distinct outcomes. Same-key resolution and authorized scheduler discovery survive even both publishers failing;
retry does not repeat issuance or its audit fact. Committed issuance is neither confirmed credential delivery,
enrollment activation nor permission to launch: each requires its own confirmed outcome and current authorization.
This exception does not change revocation, AuthenticationService or other CommandHandlers. Failure/restart behavior
tests and consumer conformance are required before acceptance of the relevant implementation/integration; this
planning decision is not proof of delivery or runtime readiness.

Credential-operation limits have documented finite defaults and optional validated consumer overrides; routine
operation/recovery needs neither manual configuration nor an extra human approval step. Current authorization still
applies. Capacity exhaustion rejects or defers new work with a clear retryable outcome while preserving safe status
lookup and recovery of existing operations. Cleanup retains the evidence needed to prevent duplicate issuance and
stale delivery. Concrete values and implementation choices belong in requirement/design work before implementation
acceptance, not a wider quota or approval system.

## Planning and Completion

Local TASK files under `planning/tasks/` are canonical for implementation scope, status, dependencies,
acceptance, and evidence. The Board ranks ready work. A TASK is executable only under the rules in
`planning/agents/issue-tracker.md`. Run `./bin/planning-check` for planning integrity and `./bin/build` for the
complete package gate.
