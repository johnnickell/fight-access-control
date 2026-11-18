# Request authority and continuation contracts

These unreleased contracts are owned by [TASK-00145](../planning/tasks/00145-TASK.md). They preserve separate
User session/Role authority and Agent credential/direct-Permission authority; typed IDs or snapshots grant nothing.
Consumers still own authentication adapters, grants, target relationships, cryptography, persistence and runtime.

## Current request composition

Compose a fresh `CurrentPrincipalProvider` from the trusted `AuthenticationContextProvider` and
`AuthoritativePrincipalResolver` for each User request. The provider retains only a successfully resolved immutable
snapshot for that short request. Missing/inactive User, missing/revoked/expired session, wrong session owner and stale
authentication versions are known refusals. A repository returning another requested identity, an incomplete or
malformed Role/Permission set, contradictory reserved Role identity or an operational failure is an internal fault,
not expected authentication rejection. Role-derived Permissions are complete and deduplicated by identity.

Compose a fresh `CurrentAgentPrincipalProvider` with the actual `SignedAgentRequest` for each Agent request.
Authentication verifies the exact credential lookup before cryptography, accepts the valid signature, commits nonce
consumption once and then rechecks current Agent authority. A later snapshot/resolution failure does not restore the
nonce. Direct Agent definitions must remain ADMIN_SAFE; protected-tier definitions are invalid Agent snapshots.
Successful repeated resolution in the same short request retains the complete immutable snapshot and original
authentication time. It does not authenticate another request or authorize continuing work.

Agent public diagnostic text remains generic. Secret-free classifications distinguish known authentication/current-
authority refusal from invalid Permission snapshots and opaque resolution failure. `RequestAuthority` translates only
known refusals to Common `AuthenticationRequired`; invalid/opaque snapshots remain internal safe-error outcomes.
Unknown User faults propagate unchanged. Never classify by message, integer code or a private cause.

`SignedAgentRequest` is a sensitive synchronous input, not a serializable message or worker handoff. Serialization and
hydration reject; debug/JSON projections expose no fields. Its explicit getters remain available only to trusted
request/verifier composition. Sensitive-parameter annotations do not sanitize arbitrary consumer logs or exceptions.

## Exact sets and neutral admission

`ExactPermissionResolver` requires a list of unique `PermissionId` values and returns complete definitions or principal
snapshots in requested order. The repository may reorder the result but must return a list of valid definitions with
exact distinct IDs and canonical names. Missing, extra, duplicate, malformed or wrong-identity results are internal
contract failures. Operational failures propagate. The same resolver serves administration, reads and continuation;
it does not supply a grant, tier policy or lock by itself.

`PermissionDeclaration` intentionally accepts dynamic trusted configuration and validates list shape, members,
duplicates and mode consistency at construction. PUBLIC and AUTHENTICATED have no Permission requirements;
PROTECTED requires a nonempty conjunctive set. Missing declarations are configuration faults, never implicit public
access. `PermissionAdmission` returns the selected request snapshot or throws an intentional authentication/permission
refusal. Passing admission does not grant record/relationship scope or a reusable continuing allow.

Common's opt-in safe HTTP boundary maps known authentication/permission outcomes separately from internal faults.
Consumers must supply appropriate authentication challenge metadata; protocol-specific MCP concealment and errors
remain separate. Existing Tool neutral pre-admission containment and Resource/Skill operational propagation are not
replaced with a universal HTTP mapping. See [HTTP admission](http-permission-admission.md) and
[MCP authentication composition](agent-mcp-authorization.md).

## Continuing bounded effects

Follow the [continuation guide](agent-continuation-authorization.md). Trusted request provenance or a separately
verified handoff binds the original Agent/credential/revision and original time. Every bounded action rechecks current
credential, complete direct ADMIN_SAFE Permissions, cohort/restoration, conjunctive requirements, consumer scope and
original lifetime under the actual all-writer composite fence. Continuation never replays HMAC or consumes a nonce.

The callback is synchronous and admitted at most once; missing, deferred or repeated callback attempts cannot perform
a second effect. Pre-invocation faults retain neutral containment. After invocation begins, action/fence faults remain
faults, not denial implying no effect. The action's original throwable remains primary even when the fence also fails
or suppresses it. A fence fault after a successful action propagates with the effect already authoritative. No business
transaction, rollback promise, success Event, live lease, worker executor or new grant is introduced.

## Consumer obligations and evidence limits

Update catches that treated broken User repository snapshots as ordinary refusal. Preserve current nullable lookup
semantics and fail operationally on malformed persisted data. Update callers that attempted to serialize signed
requests: workers use separately verified continuation provenance, never saved authentication inputs or replayed nonces.
No compatibility aliases, old modes or historical readers are supplied under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md).

Package tests and static fixtures exercise actual providers, admission, exact resolution, continuation and relevant
Common/protocol composition through controlled ports. They do not qualify production crypto, consumer database races,
shared writer locks, clock behavior, handoff durability/recipient verification, scope policy, bootstrap, activation/use
or deployment. Qualify those actual components separately, including every writer and alternate entry point. Independent
Review and behavioral QA remain required; implementation evidence is not acceptance or release authority.
