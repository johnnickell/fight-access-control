# Optional User registration (Unreleased)

`RegisterUser` and `RegisterUserHandler` supply the consumer-admitted registration journey approved in
[TASK-00074](../planning/tasks/00074-TASK.md). They are distinct from `InvitePendingUser`: do not expose the private
invitation command as anonymous registration. Registration is optional consumer composition, not enabled by package
installation. This is planned v1.0.0-track, Unreleased behavior, not part of the v0.6.0 release preparation.

## Messages, admission and configuration

`RegisterUser(UserId $userId, EmailAddress $email)` has exactly `user_id` and `email` serialized fields. Generate a
new stable User ID at the consumer's admission boundary. `fromArray()` rejects missing, non-string or invalid values
and extra keys, including actor, Role and credential inputs. There is no password at registration: the existing
synchronous `AuthenticationService::activate()` takes the one-time credential and new password later.

No authenticated actor is asserted by this command or `UserRegistered`. Audit uses fixed source `registration`,
action `user.registered` and the new User ID, without email, credential or arbitrary caller context. That source
marker identifies the use case, not an authenticated User, and grants no authority. The optional delivery subscriber
uses the same source marker for immediate worker dispatch. Consumer authorization/admission cannot be derived from it.

Construct `RegisterUserHandler` with these dependencies, in order:

1. Domain `UserRepository`
2. Domain `RoleRepository`
3. Domain `ActivationGrantRepository`
4. Domain `AuditEvidenceRepository`
5. Common `TransactionalUnitOfWork`
6. Application `ActivationCredentialGenerator`
7. Application `InvitationDeliveryCipher`
8. Application Timing `Clock`
9. Common `EventDispatcher`
10. Nonempty consumer configuration `list<string> $defaultRoleNames`

Names must pass `RoleName::fromString()`. Empty configuration, invalid names and `ROLE_SUPER_ADMIN` reject handler
construction. Repeated names form one set. There is no global `ROLE_USER` requirement; other managed or custom
consumer Roles are not blanket-forbidden. Choose defaults deliberately: the package does not infer which of your
ordinary Roles is safe to grant through self-registration. Never construct these defaults from public request data.

For every command the handler resolves **all current Roles by name inside its transaction**, assigns every resolved
ID to the pending User and calls mandatory `UserRepository::addWithRoleAssignments(User, list<Role>)`. The list contains
expected complete Role snapshots, not caller authority. A missing/mismatched or conflicting Role rejects everything.
Changing defaults affects later registrations only. A later rename/redefinition does not retroactively change the
stored assignment IDs of earlier Users; normal Role authority management still applies to those IDs.

## Creation-time persistence contract

Consumers implement the new mandatory Domain method directly; there is no optional capability, compatibility default,
Application Store or production Adapter in this package. Ordinary invitation still uses `UserRepository::add()`;
name-resolved initial registration assignments must use `addWithRoleAssignments()`, not a post-commit assignment command.
`add()` itself also validates every initial Role ID under that reference fence; it is not a bypass for Role-bearing
creation. The extra expected-snapshot method binds registration's configured **names and definitions**, not only IDs.

The consumer adapter must, on the same connection and transaction:

- Atomically enforce fresh User identity and canonical-email uniqueness against **all** pending, active, disabled and
  deleted Users and live email-change reservations. Never rely on an availability preflight. A duplicate throws the
  existing internal rejection; it does not create a User, activation, delivery, successful-registration audit or fact,
  send duplicate mail, recover another User or return package generic success.
- Validate the nonempty expected Role set against the User's **exact entire initial assignment set** and authoritative
  stored Roles. Compare complete current identity, unique canonical name, managed ownership, Permission membership and
  timestamps. Duplicate/conflicting IDs or names, removal, rename and replacement must reject stale expectations.
  A matching PHP object or cached name lookup alone is not evidence of persisted authority.
- Hold the shared Role-reference/name fence through **transaction completion**, including User insertion and every
  Role add/replace/remove path (including managed-policy writers). Removal may not miss a newly created pending User's
  references. A Role replacement cannot commit between successful creation validation and registration commit.
  Return false without insertion on reference mismatch; duplicate identity/email and storage failures throw.
- Commit pending User, all Role assignments, activation grant, encrypted registration-origin delivery and audit together,
  or roll them all back. Do not commit individual repositories. Release all fences on both rollback and confirmed commit.

There are two safe writer orders: a Role writer wins first and stale registration rejects with no effects; or
registration wins the fence, completes, and a later removal sees the assigned reference. A replacement may serialize
after completed registration but may not overtake its fenced validation. Database blocking, retryable conflict handling
and deadlock policy belong to the adapter. Package tests model conflict rejection, not physical lock waiting.

The activation grant explicitly stores `ActivationOrigin::REGISTRATION` and a seven-day expiry from issuance. Its
hashed single-use authority and separately encrypted delivery material commit with the pending User. Persist the origin
with the full grant/delivery state and include it in complete-state CAS; follow the
[current delivery contract](credential-delivery.md). No historical reader, inferred origin or migration is supplied
under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md).

## Publication, provider and restart

After confirmed commit the void handler publishes `UserRegistered(userId, activationDeliveryId, email, issuedAt)`.
The immutable fact round-trips without credentials, Role authority or authenticated-actor claims. Its issuance instant
is explicit RFC3339 and preserves fractional seconds. It confirms pending registration, **not** mail arrival or activation.
The opt-in OpenAPI components `Fight.AccessControl.RegisterUser` and `Fight.AccessControl.UserRegistered` describe these
values, not endpoints, response bodies or an installation setting.

Register `InvitationDeliverySubscriber` to route `UserRegistered` to `DeliverUserInvitation`, alongside its existing
private invitation/resend/retry/restore registrations. The worker commits a claim, then invokes the consumer's
`CredentialDeliveryProvider` **outside every transaction**, using the stored registration origin and stable delivery ID.
Consumers choose their own registration template separately from private invitation. Provider acceptance alone leaves
the User pending with its configured Roles. The existing single-use password-setting activation activates that **same**
User and preserves its Role assignments; replay cannot activate a second identity or issue another session.

Run protected `FindDueCredentialDeliveries` discovery and matching direct worker dispatch even when using subscribers.
Scheduler restart recovers pending work without replaying registration or needing its success event. Unexpected provider
faults become safe retryable delivery failure; leases/retries may repeat provider invocation with the same delivery ID.
The guarantee remains at-least-once, not exactly-once email or reliable event publication.

Every registration operation failure attempts `CommandFailedEvent` publication with the original safe `RegisterUser` and constant
`User registration failed.` text, then rethrows the **same original throwable**, even if failure publication also fails.
Arbitrary storage/subscriber diagnostics can contain row or credential material and are not copied into failure events.
Consumers must still sanitize the rethrown throwable and protect event/audit access (messages contain email/identity).

- A pre-commit write/reference/commit failure rolls back every registration effect.
- A post-commit publication or subscriber fault does **not** roll back the complete registration. Both success and
  failure publishers may fail while durable delivery remains discoverable. No typed success-warning exception for Agent
  issuance is imported here; this is a void handler with normal failure/rethrow behavior.
- An indeterminate commit is not a rollback claim. Trusted operators can use existing consumer-protected `GetUserById`,
  `FindInvitationDeliveryStatus` or `FindCredentialDeliveryStatus` reads against authoritative storage after restart.
  A found pending User/default Roles and matching delivery distinguish the modeled committed outcome; absence amid
  unavailable/non-authoritative storage is not proof of rollback. No new public existence query is supplied.
- Lost caller response is not an idempotent registration protocol. Repeating a claimed email still rejects; it does not
  replay the fact, reissue activation or silently reuse another User. The scheduler recovers existing delivery. Authorized
  administrator registration correction/fresh activation remains separate TASK-00075 work, not anonymous recovery.

Private invitations retain `PRIVATE_INVITATION`, private correction rejects registration, and deleted-to-pending restore
starts a fresh private invitation. Password-reset and email-change purpose/origin/claim behavior are unchanged.

## Consumer-owned security and unexecuted qualification

Consumers choose whether/where to expose registration, including installation database settings. Protect **every**
HTTP/CLI/queue/other entry point with appropriate admission, validation and public abuse controls. Throttling, bot
challenges, abuse logging, response status/text and account-enumeration evaluation are entirely consumer-owned. Internal
duplicate rejection is intentional and is not a promise of public concealment or a package generic acknowledgement.
Existing protected operational reads must not become public identity oracles. Defaults, query access and delivery
scheduling all require trusted composition. This package supplies no page, route, middleware, template renderer, SQL/mail
adapter, enablement switch, anonymous recovery or consumer adoption/deployment permission.

Before exposure, qualify these **unexecuted real-consumer obligations**: shared database transaction/connection and
rollback; canonical email plus reservation uniqueness under competing inserts; complete Role identity/name and reference
fences across creation/removal/replacement and managed-policy writers in both winner orders; ORM hydration and CAS;
physical process/commit uncertainty and restart; cipher/key handling; provider idempotency and distinct configurable
private/registration templates; actual scheduler behavior; public disclosure, abuse/load, logging and response policy.
Package passes do not close any of these obligations or authorize release, activation in a consumer, or deployment.

## Package evidence map

| Criterion | Executable package evidence |
| --- | --- |
| C1 | `RegisterUserTest`, `RegisterUserHandlerTest`, subscriber registration/provenance test |
| C2 | Handler configuration, all-defaults/deduplication, missing/conflicting names/IDs, fresh resolution on the same handler, earlier-User retention tests |
| C3 | `RegistrationCreationContractTest`; `RegistrationReferenceRaceTest` removal/rename/replacement/same-name recreation winner and held-fence rollback/commit schedules; insert/grant/audit/commit rollback tests |
| C4 | Every lifecycle state plus live destination reservation; competing canonical registration with exactly one complete winner |
| C5 | `RegistrationJourneyTest` uncertain rolled-back/committed creation, both publishers failing, lost response, provider fault/restart and exact delivery ID/origin recovery |
| C6 | Real registration → subscriber/Common routing bus → package worker/provider → same-User single-use activation; protected User/status reads; existing invitation/reset/email tests |
| C7 | `RegistrationComponentsTest` generates the opt-in catalog with a consumer root and compares actual payloads, fields/formats and forbidden authority/secret inputs |
| C8 | TASK-owned focused/full gate logs, direct exits, coverage and content receipt; independent Review and nonvisual QA remain separate |

The shared test repositories model separate connections, persisted state, transaction-duration conflict rejection and
rollback. Grant restart reconstructs current origin/delivery state; fresh User wrappers read retained state. These are
controlled package-port schedules, not real parallel SQL sessions, a restarted operating-system process or a qualified
mail/security runtime. See the TASK checkpoint and ignored Work handoff for exact current counts and limitations.
