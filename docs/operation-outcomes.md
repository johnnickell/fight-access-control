# Operation outcomes

The current package contract separates business persistence, required audit, notification and consumer presentation.
Commands and synchronous authentication methods retain their established authorization and transaction boundaries.
Queries remain read-only. This composition is published in [v0.8.0](release-0.8.0.md), not part of v0.7.0;
the overview separates verified package publication from unexecuted consumer qualification.
Unreleased [User/session type validation](user-session-type-contracts.md) preserves these outcomes and fences;
shape validation is not authorization, diagnostic sanitization or consumer persistence qualification.

## Notifications and callers

Package-produced failure notifications use operation-specific constant text with the original safe Command or
allowlisted context. The [direct synchronous User capabilities](user-authentication.md) emit `RedactedCommandFailed`
with their current owner class/method and never serialize passwords or credentials as Commands. Existing expiry notifications retain their constant cleanup text. Do not parse
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
- The confirmed-commit publication-containment exception remains recoverable Agent provision and rotation.
  TASK-00182's unreleased void Commands complete even if both publishers fail; independent authorized Queries expose
  original issuance/dispositions, with no returned or reconstructed warning. Lost acknowledgement throws safe
  execution uncertainty, not success or rollback. This exception does not apply to revocation, authentication or
  other handlers; see
  [issuance](agent-provisioning-operations.md) and [rotation](agent-rotation-operations.md).

## Consumer composition

Failure subscribers must accept coarse notifications without requiring dependency diagnostics. Keep response/log
sanitation independent of subscriptions. Optional synchronous delivery subscribers run after issuance commits; a
worker fault may therefore propagate through the original request even though issuance is durable. Read current
[delivery status and due work](credential-delivery.md), retain exact generation identities, and keep provider retry
classification distinct from outer operation failure notification. No outbox or guaranteed event replay is supplied.

## Account lifecycle failures (Unreleased)

The eight [User lifecycle handlers](user-lifecycle-contracts.md) now expose intentional Common Application
absence, target denial, state conflict and explicit recovery-precondition outcomes. Wrong identities, broken
configuration and unknown faults remain internal. Domain stays independent; no blanket transaction-wide exception
translation, result DTO, new no-op event or changed uncertainty/publication policy is introduced.

## User authentication transitions (Unreleased)

User owns complete business transitions: `activate()`, `changePassword()`, `resetPassword()` and
`confirmEmailChange()` each advance `authenticationAuthorityRevision` exactly once. Activation preserves
`authenticationVersion`; password change/reset and confirmed email advance it once. Lifecycle semantics remain
separate: disable/delete advance the security version, while enable/restore preserve it; none adds a login revision.

After credential verification and throttle admission, `UserAuthentication::login()` calls
`recordSuccessfulLogin($authenticatedAt, $rehashedPassword)` on a cloned User. The optional hash is present only
when the verified password needs rehash. Both forms advance the persistence revision once without changing the
security version or recovery authority. A rehash updates password/update metadata; a no-rehash login preserves it.
The transition requires an active identity with an established password; it does not itself verify a raw password,
check a throttle, persist a session or grant authentication outside the use case.

For example, an existing password-change transaction now constructs its complete successor with:

```php
$replacementUser = clone $user;
$replacementUser->changePassword($passwordHash, $changedAt);
```

Persist that successor through the existing `UserRepository::replaceAuthenticationAuthority()` inside the same
Unit of Work as recovery cancellation, session revocation and required audit. Do not separately increment a revision.
Login and activation still require `replaceAuthenticationAuthorityAndAddRefreshSession()`; email confirmation retains
its dedicated full-state replacement. Aggregate encapsulation does not replace complete expected-state CAS,
exact-one revision validation, coupled session insertion, single-use grants or transaction-duration fences.

This is a breaking pre-v1 PHP API change: update callers directly to the complete transition. The standalone
revision increment and standalone password-rehash APIs are removed, without compatibility aliases. Persist/hydrate
actual current counters, never reconstruct them from lifecycle state or add a second activation bump. Safe token
results, sensitive synchronous inputs, redacted failure evidence and pre-/post-commit outcomes above are unchanged.
Package tests use controlled repositories and modeled races; consumers still qualify their actual database, crypto,
shared connections and concurrent writers independently. No new grant, event or exactly-once promise is introduced.

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
- `PasswordManagement`, `EmailChangeConfirmation` and `RequestPasswordResetHandler`: retain shared User/reset-grant/
  audit composition. Their [direct constructors](user-authentication.md#explicit-composition) need no unrelated
  activation, login or token ports.

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

The shared planner validates exact ID/name lookups and complete managed/reference batches before using them.
Batches must be lists of the promised aggregate, without repeated IDs/names; managed inventories contain only managed
records and containing-Permission reads contain that exact Permission ID. Invalid repository relationships throw
internal `UnexpectedValueException`, not a plan or ordinary refusal. Native return violations and unknown faults
propagate unchanged. These checks establish no atomic snapshot across separate reads.

Known planner policy refusals now throw Common Application `RuleViolation` (safe generic HTTP 422 through Common's
opt-in safe middleware), rather than Domain `ManagedPolicyDefinitionException`. The latter still owns malformed
policy construction/decoding in Domain and is not blanket-translated. Update catches for planner/preview/apply
refusals directly; do not map arbitrary Domain/programmer/provider faults to rule rejection. Reserved-identity
inconsistency remains internal. No private exception text belongs in public responses.

For example, preview the complete desired `ManagedPolicy` through `PreviewManagedPolicy`, then dispatch
`ReconcileManagedPolicy` with that same value. Preview is a snapshot, **not a reservation or authorization**. Apply
replans inside its Unit of Work, checks each subsequently reread identity before mutation and still calls
`PermissionRepository::remove(expectedPermission)` for every removal.
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

### Serialized policy types (Unreleased)

`ManagedPolicy::fromArray()` and its preview/reconcile messages validate dynamic decoded input: `permissions`,
`roles` and `referenced_permission_ids` are lists, definition items are arrays, and IDs/names/tiers are strings.
Role definition `permission_ids` is a list of strings. Values are not obtained by casting objects/scalars into the
current shape. Typed PHP constructors retain their existing list-of-value preconditions. Valid wire payloads,
managed membership and tier rules are unchanged; update malformed producers without compatibility defaults.

`ManagedPolicyPlan::toArray()` and `ManagedPolicyReconciled::getPlan()` expose exact static item shapes:
Permission items contain `id`, `name`, `tier`, `action`; Role items contain `id`, `name`, `permission_ids`, `action`.
The Event retains runtime validation of dynamic plan input and requires a nonempty string `occurred_at` when decoded.
Richer annotations do not turn preview, an Event or a principal snapshot into a reservation or authorization.

## Role revision composition

Custom Role detail/list Views expose required `revision`; rename/remove require the displayed state's
`expected_revision`. All Role writers and registration snapshot comparisons share persisted incarnation/revision
and final transaction-duration fences. Fresh same-name requests write/publish nothing; stale intent raises a safe
typed conflict and requires refresh/reconfirmation, not silent token substitution. See the
[Role revision contract and consumer-bindable scenarios](role-revisions.md) for exact current payload, hydration,
all-writer and adapter obligations. Existing membership inputs and policy-level facts remain unchanged.

This is the consolidated current B1–B4 consumer composition guide, not a release or qualification receipt. Actual
slice identities, local verification and independent-stage disposition are retained by the maintainer handoff.
TASK-00077–00080 have independent technical acceptance and behavioral QA and are integrated into the release base.
That acceptance does not replace independent release-content review or final-commit certification. Consumers must
independently qualify real shared-connection races in both winner orders, rollback/uncertain commits, response loss, persistence
hydration, worker restarts, provider idempotency/delayed outcomes, permissions and response/log handling. Keep keys,
raw credentials, hashes and ciphertext out of public evidence. No consumer adoption, release or deployment is
authorized by this guide.
