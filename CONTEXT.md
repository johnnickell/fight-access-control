# Fight AccessControl Context

## Purpose

Fight AccessControl owns framework-neutral identity, credential, session, authorization, and account-lifecycle
behavior shared by Fight applications. The repository-local behavioral and security authority is
[PRD-00001](planning/specs/00001-PRD.md).

## Vocabulary

- **User**: the stable identity whose canonical email remains unique across pending, active, disabled, and
  deleted states.
- **Grant**: a purpose-bound, hashed, expiring, single-use credential for activation, password reset, or email
  change. Reissue revokes its predecessor.
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
- **Current Agent principal provider**: a consumer-composed, request-scoped service that authenticates one signed
  Agent request and returns its cached immutable Authenticated Agent principal for that request.
- **Agent authentication diagnostic**: a secret-free, server-observable classification and correlation identifier
  for a failed Agent authentication. It is not disclosed to an untrusted caller.
- **Signed Agent request**: the transport-neutral representation of the Fight Common HMAC v1 canonical request;
  consumer applications map transport data into and out of this representation.
- **Managed Permission and Managed Role**: stable version-controlled authorization definitions reconciled
  exactly and atomically.
- **Conformance suite**: reusable tests of observable Domain and Application outcomes which consumer repositories
  bind to their own adapters.

## Package Boundary

Production dependencies flow `Domain <- Application`. Domain owns invariants and lifecycle state. Application
owns a synchronous secret-bearing authentication service, explicit non-sensitive commands and queries, portable
ports, immutable token/read results, and transaction orchestration. This package has no production Adapter layer and no
framework dependency. Consumers own clients, persistence, HTTP and cookie adapters, cryptographic keys, mail,
queues, realtime delivery, hosting, and runtime composition.

## Planning and Completion

Local ticket files under `planning/tickets/` are canonical for implementation scope, status, dependencies,
acceptance, and evidence. The board ranks ready work. A ticket is executable only under the rules in
`planning/agents/issue-tracker.md`. Run `./bin/planning-check` for planning integrity and `./bin/build` for the
complete package gate.
