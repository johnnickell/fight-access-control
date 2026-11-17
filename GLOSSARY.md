# AccessControl glossary

## People and credentials

- **User**: A stable human identity whose canonical email remains unique across pending, active, disabled and deleted states.
- **Grant**: A purpose-bound, expiring, single-use credential for account activation, password reset or email change.
- **Activation origin**: The distinction between a private invitation and self-registration that produced an activation grant and its delivery.
- **Recoverable credential delivery**: Retained activation, password-reset or email-change work that can resume after interruption without issuing another grant. [Composition](docs/credential-delivery.md).
- **Refresh session**: The authoritative server-side session that owns refresh-credential rotation, revocation, activity, lifetime and coarse device information.
- **Browser authentication response**: The authentication response containing access-token material and session metadata, but no refresh credential.
- **Portable token set response**: The authentication response for a non-browser client or explicit body-token profile, including the sensitive refresh credential.

## Authority and policy

- **Authenticated principal**: An immutable identity and Permission snapshot established from authoritative authentication state for one request.
- **Authenticated Agent principal**: An authenticated principal carrying an Agent's identity and direct Permissions, rather than User Role-derived authority.
- **Security context**: The request-specific authority of exactly one authenticated User or Agent, exposing its Permission and Role checks.
- **Permission**: A stable authorization definition with an identity, canonical name and delegation tier; Users receive Permission authority through Roles and Agents through direct assignments.
- **Principal Permission**: The immutable Permission identity and canonical name captured in an authenticated principal, not the authoritative Permission definition.
- **Role**: A named set of Permissions assignable to Users, not Agents.
- **Managed Permission / Managed Role**: A version-controlled authorization definition whose stable identity and declared state are reconciled by managed policy.
- **Custom Permission**: A runtime-owned Permission with the `ADMIN_SAFE` tier, rather than a managed definition.
- **Super Admin Role**: The authoritative managed Role named exactly `ROLE_SUPER_ADMIN`, the only Role eligible to hold protected managed Permissions.
- **Permission tier**: The distinction between `ADMIN_SAFE` authority eligible for delegation and `SUPER_ADMIN_ONLY` authority reserved for human Users through the Super Admin Role. [Policy](docs/permission-tiers.md).
- **Protected managed Permission**: A managed Permission classified `SUPER_ADMIN_ONLY`, rather than an ordinary delegable Permission.
- **Desired authorization state**: The requested membership set, where already-present grants and already-absent revocations are unchanged outcomes rather than new changes.

## Agents and credential operations

- **Agent**: A machine principal with direct Permission authority, not a human User, refresh session or AI persona.
- **Agent credential**: One HMAC authentication authority belonging to an Agent, identified by a public credential ID and backed by a shared secret.
- **Agent credential revision**: The monotonically advancing version of an Agent's single credential authority, independent of its Permission assignments.
- **Agent direct Permission assignment**: An Agent-owned, duplicate-free set of stable Permission identities, without Agent Roles.
- **Agent Permission-assignment revision**: The monotonically advancing version of that direct assignment set, changing only when its membership changes.
- **Agent profile**: The minimal current active Agent identity and name, separate from its administrative read result.
- **Agent read result**: A secret-free administrative snapshot of Agent identity, name, lifecycle, credential identity/revision and direct Permission assignments/revision.
- **Signed Agent request**: The transport-neutral authentication input representing a Fight Common HMAC v1 canonical request.
- **Agent authentication diagnostic**: A secret-free server-observable classification and correlation identifier for failed Agent authentication, not a caller-facing explanation.
- **Agent credential operation**: A caller-scoped retained provisioning or rotation request binding its original issuance and delivery outcome; retiring delivery material does not make its key reusable. [Composition](docs/agent-integration.md).
- **Agent issuance**: The original Agent/credential/revision and delivery-destination binding created by provisioning or rotation, distinct from delivery, enrollment activation and permission to use the credential.
- **Agent operation status**: An authorized secret-free observation separating confirmed original issuance, delivery disposition and original-credential disposition; absence is indeterminate rather than proof of rollback.
- **Agent operation cohort**: A compatible set of Agent and credential-operation writers sharing one persisted contract and monotonic generation. [Contract](docs/agent-operation-cohorts.md).
- **Agent canonical request version**: The marker identifying the supported canonical representation of a retained credential-operation request. [Contract](docs/agent-canonical-upgrades.md).
- **Agent protected delivery**: Delivery of original credential material to its fixed authorized sink, distinct from issuance and from activation or use authority.
- **Agent delivery maintenance**: Rewrapping retained delivery ciphertext, expiring original delivery material and confirming cleanup of inert sink copies. [Composition](docs/agent-delivery-maintenance.md).
- **Agent continuation binding**: Provenance tied to the originally authenticated Agent/credential/revision and original lifetime, not a continuing Permission or scope grant.
- **Agent continuation admission**: Current authority and consumer scope for one bounded protected action under a shared writer fence, not a reusable allow. [Composition](docs/agent-continuation-authorization.md).

## Features

- **Feature**: A named availability restriction with a stored testing Permission and OFF, PREVIEW or ON status, separate from authorization for the underlying action.
- **Feature availability**: Whether the Feature restriction permits access: OFF denies, PREVIEW depends on the testing Permission and ON removes only that restriction. [Meaning](docs/feature-availability.md).
- **Feature preparation**: Validation that a complete candidate's Feature references have valid stored definitions and Permission bindings, not runtime availability or permission to deploy.
- **Feature discovery scope**: The distinction between references in the complete candidate under preparation (`CANDIDATE`) and those in the currently deployed code (`CURRENT`). [Definitions](docs/feature-references.md).
