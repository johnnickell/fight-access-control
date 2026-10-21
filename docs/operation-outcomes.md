# Operation outcomes

The current package contract separates business persistence, required audit, notification and consumer presentation.
Commands and synchronous authentication methods retain their established authorization and transaction boundaries.
Queries remain read-only.

## Notifications and callers

Package-produced failure notifications use operation-specific constant text with the original safe Command or
allowlisted context. Synchronous `AuthenticationService` methods emit `RedactedCommandFailed` and never serialize
passwords or credentials as Commands. Existing expiry notifications retain their constant cleanup text. Do not parse
notification text for transport decisions or treat a notification as a durable outcome receipt.

Failure notification is best effort. A notification fault cannot replace the operation's primary throwable.
Established generic authentication rejections remain generic. **Rethrown exceptions are not sanitized by the
notification policy**: consumers must separately sanitize responses and logs and protect identity-bearing events.

## Persistence and publication

- Confirmed pre-commit failures roll back staged mutation and required audit, with no success event.
- Success events publish only after confirmed commit. A subsequent publisher or synchronous subscriber fault still
  throws; it does not undo the committed state or acknowledge success to the caller.
- Lost commit acknowledgement is not proof of rollback. Reconcile using authoritative current state under current
  authorization rather than inferring outcomes from events or blindly replaying a command.
- The sole committed-result publication-warning exception remains recoverable Agent provision and rotation. Their
  typed warning does not apply to revocation, authentication or other handlers; see
  [issuance](agent-provisioning-operations.md) and [rotation](agent-rotation-operations.md).

## Consumer composition

Failure subscribers must accept coarse notifications without requiring dependency diagnostics. Keep response/log
sanitation independent of subscriptions. Optional synchronous delivery subscribers run after issuance commits; a
worker fault may therefore propagate through the original request even though issuance is durable. Read current
[delivery status and due work](credential-delivery.md), retain exact generation identities, and keep provider retry
classification distinct from outer operation failure notification. No outbox or guaranteed event replay is supplied.

## Recovery composition

Password-reset generations carry immutable `User\\RecoveryAuthority`: the issuing `authenticationVersion` and
`canonicalEmailRevision`. `PasswordResetGrant::issue()` requires it as the final argument; the protected hydration
constructor requires it immediately after the delivery. Persist both integers with every generation, including
terminal history. Construct `RecoveryAuthority` from those stored values (positive authentication version,
nonnegative canonical-email revision), never from the latest User during hydration. There is no missing-value
default, historical reader or package migration. These are internal persisted values, not new caller input or
OpenAPI fields. Compare them in both operands of complete-state CAS and preserve them in every same-generation
transition. Existing identity, digest-history, delivery-generation, revision, claim and lease comparisons still apply.

`User::getRecoveryAuthority()` supplies the current values. Confirmed canonical-email and password changes advance
security authority. Disable/delete also advance authentication version and atomically revoke outstanding reset
authority and destroy its delivery material. Enable/restore does not issue or restore recovery. Ordinary login,
rehash, refresh/logout, Role changes and unconfirmed email request/cancellation/expiry do not independently advance
recovery authority. Redemption remains single-use, expiry-bound and restricted to the current eligible owning User;
it does not require delivery success. A delivery result never proves redemption eligibility.

### Shared connection and all writers

Implement mandatory `UserRepository::validateRecoveryAuthority(expectedUser)` against complete authoritative User
state. Successful validation holds the same per-User fence as every User and password-reset grant writer through
transaction completion, even without a User mutation. Issuance validates the requested canonical identity under
that fence before persisting a pristine matching generation. Redemption couples validation, consumption, User
replacement, session revocation and required audit. Authority-changing operations couple cancellation and User
mutation with their existing audit; failure rolls back both. No cancellation is deferred until after commit.

Use one real connection/Unit of Work and a consistent **User-before-grant** lock order. All authentication, lifecycle,
email, Role, direct repository, issuance, replacement, revocation, cleanup and worker writers participate. A prior
read, an ORM identity-map object, autocommit validation or a startup check cannot substitute for transaction-duration
admission. Both integers must remain monotonic and must fail closed on invalid/out-of-range persistence or overflow.
Direct grant issuance and claim/outcome persistence cannot bypass current eligible User authority validation.

Current constructor composition requires:

- `DeliverPasswordResetHandler`: append the shared `UserRepository` after the dispatcher.
- `DisableUserHandler` and `DeleteUserHandler`: append the shared `PasswordResetGrantRepository` after the dispatcher.
- `AuthenticationService` and `RequestPasswordResetHandler`: retain their existing shared User/grant/audit composition.

The reset worker validates current User authority in its claim and outcome transactions. Decryption and the provider
call remain outside transactions. An admitted invocation can arrive late externally; its outcome cannot restore
cancelled authority or acknowledge a successor. No withdrawal of sent mail, distributed provider transaction or
exactly-once effect is promised. Due discovery returns only current eligible matching authority. A lost provider or
commit acknowledgement is reconciled from persisted generation/claim state; lease recovery retains the delivery ID.
Unknown/ineligible requests retain their generic no-work result. Protect every query and alternate entry point;
actor provenance, status views and advisory discovery results confer no permission.

### Qualification boundary

Bind `RecoveryAuthorityConformance` through three protected hooks:

- `repository(User $user)`: create isolated persistence, commit the supplied eligible User with its exact identity,
  canonical email and security values through the shared connection/Unit of Work, then return its bound grant
  repository. Do not fabricate User state inside grant writes or bypass required authority admission.
- `transaction(callable $operation): void`: execute each supplied operation through that composition's actual Unit of
  Work, committing on success or rolling back and rethrowing on failure.
- `restart(PasswordResetGrantRepository $repository)`: reopen both authoritative User and grant persistence, hydrating
  all stored fields without creating Users, issuing replacements or inferring authority from the latest User. Rebind
  subsequent `transaction()` calls to the reopened connection/Unit of Work.

The suite checks persisted authority through claims/outcomes/restart, complete-state predecessor, replacement and
terminal-successor comparisons, rejection of missing/mismatched User authority before and after reopening, and
transaction rollback. The package binding uses shared User state and a transactional Unit of Work throughout; its
restart copies persisted User snapshots and rehydrates grants into fresh modeled state. `RecoveryLifecycleTest`,
`RecoveryConcurrencyTest` and `RecoveryOutcomeTest` supply package-port lifecycle, all-writer fence and
interrupted-completion scenarios. Controlled in-memory connections and restart models are not SQL isolation,
actual User row-mapping qualification or physical-process proof.

## Managed-policy composition

`PreviewManagedPolicyHandler` and `ReconcileManagedPolicyHandler` use the same required `ManagedPolicyPlanner`
composition: Permission, Role, User and Agent repositories. Supply authoritative, unpaginated reference reads on the
same persisted dataset. Omitted managed Permissions are eligible only when consumer code, retained Role membership,
Agent assignments and stored Features do not reference them. Managed Roles being removed or reconciled may release
their memberships within the complete policy; custom Role and Agent assignments are not implicitly stripped.
Repository outages propagate, never become reference absence. Preview writes, commits and publishes nothing.

For example, preview the complete desired `ManagedPolicy` through `PreviewManagedPolicy`, then dispatch
`ReconcileManagedPolicy` with that same value. Preview is a snapshot, **not a reservation or authorization**. Apply
replans inside its Unit of Work and still calls `PermissionRepository::remove(expectedPermission)` for every removal.
Every Role, Agent and Feature reference-changing writer, including direct writes and validated no-ops, shares that
transaction-duration reference fence and actual connection with removal. A prior read or ORM snapshot cannot replace
final expected-state and reference validation. A reference winner rejects removal; a removal winner rejects a later
reference to the absent identity. See [Permission reference composition](feature-permission-references.md).

Earlier policy creates, updates and removals roll back together on a pre-commit conflict or persistence failure.
`ManagedPolicyReconciled` publishes only after commit. Publication failure still throws with committed state retained;
best-effort constant failure notification cannot replace the primary throwable. No successful-warning return is
introduced. Preserve deterministic plan order, exact managed membership, tiers and reserved Super Admin identity.
Consumers protect both operation entry points; supplying an actor or a policy never grants administrative authority.

The package's public-handler and controlled reference-writer scenarios exercise unchanged-state eligibility, both
ordered outcomes, late final-boundary conflicts, rollback, eligible retry and publication faults. They do not qualify
SQL isolation, real adapter locks or independent processes. Qualify those against the actual shared transaction,
including both concurrent winner orders, expected-state conflicts, rollback and indeterminate commit outcomes.

This is the current B1/B2/B3 consumer handoff; Role caller revisions remain separate work. Consumers must independently
qualify real shared-connection races in both winner orders, rollback/uncertain commits, response loss, persistence
hydration, worker restarts, provider idempotency/delayed outcomes, permissions and response/log handling. Keep keys,
raw credentials, hashes and ciphertext out of public evidence. No consumer adoption, release or deployment is
authorized by this guide.
