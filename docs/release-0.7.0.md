# v0.7.0 release and integration overview

[v0.7.0 was published](https://github.com/johnnickell/fight-access-control/releases/tag/v0.7.0) on 2026-10-20
from the exact certified main release merge `cf55a25ade9c36f99c9211f12e467446a5bb0c9d`, integrated through
[PR #124](https://github.com/johnnickell/fight-access-control/pull/124). Its human-signed annotated tag object is
`79de80627128ace44c38fe7a80e063e7115335f0`; local signature and remote object/peeled commit were independently
verified. GitHub release 418551512 is latest, non-prerelease and immutable with zero uploaded assets, and its
provider attestation verifies the tag object. Packagist source and dist references match the certified commit.
These publication facts do not establish installed-consumer qualification, enablement or deployment.

## Scope and compatibility

This breaking pre-v1 minor adds the complete optional User registration capability accepted in
[EPIC-00013](../planning/epics/00013-EPIC.md): durable activation origin ([TASK-00073](../planning/tasks/00073-TASK.md)),
atomic registration/default Roles ([TASK-00074](../planning/tasks/00074-TASK.md)), and administrator recovery
([TASK-00075](../planning/tasks/00075-TASK.md)). All three are independently accepted with behavioral QA and integrated
through PRs [#121](https://github.com/johnnickell/fight-access-control/pull/121),
[#122](https://github.com/johnnickell/fight-access-control/pull/122) and
[#123](https://github.com/johnnickell/fight-access-control/pull/123).

- PHP **8.5+** and Fight Common **^1.3** requirements are unchanged.
- This is **not a v0.6.x patch**: existing consumers must implement the current repository, persisted origin,
  invocation and status-view contracts even if they do not expose registration.
- Only the current pre-v1 API and persisted contract are supported under
  [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). No compatibility defaults, historical readers,
  prior-data conversion or migration/backfill machinery are supplied. This does not authorize destructive data resets.
- The EPIC's v1.0.0 planning target means completion before a separate stability decision, not a v1.0.0 release promise.
  v0.7.0 does not enable registration in Agent OS, starters or any other consumer.

## Required consumer composition

| Boundary | v0.7.0 contract and required action |
| --- | --- |
| Activation issuance, hydration and persistence | Supply immutable `ActivationOrigin` as the first argument to grant issuance/hydration. Persist `private_invitation` or `registration` and compare it in complete-state CAS, including terminal predecessors. No inferred origin/default is accepted. See [activation origin](credential-delivery.md#activation-origin-v070). |
| Provider invocation | Supply the required fifth `?ActivationOrigin` argument: stored origin for activation, explicit null for reset/email change. Select independently configurable consumer templates through `getActivationOrigin()`, not route/event history. Purpose remains `activation`; delivery ID remains the idempotency identity. |
| Initial User Roles | Implement mandatory `UserRepository::addWithRoleAssignments()` against exact current Role snapshots and the complete initial assignment set. Hold the Role-reference/name fence through transaction completion across every Role add/replace/remove path. Ordinary `add()` must also validate initial Role IDs; it cannot bypass the fence. See [registration persistence](user-registration.md#creation-time-persistence-contract). |
| Pending-registration replacement | Implement mandatory `UserRepository::replacePendingRegistration()` using complete expected User state. Hold the shared identity/claim fence even for unchanged-email validation, through completion, with every User writer. All activation writers share the generation fence. Couple User, predecessor revocation, successor delivery and audit atomically. See [recovery persistence](registration-recovery.md#composition-and-atomic-persistence). |
| Recovery authorization | Bind `RegistrationRecoveryAuthorization` to trusted current administrator context and exact target policy inside the transaction, valid through completion. Protect every dispatch/query path as well; actor IDs and route admission alone are insufficient. |
| Protected status and OpenAPI | `InvitationDeliveryStatusView` requires typed `ActivationDeliveryId` and exposes `activation_delivery_id` for exact recovery expectations. Update constructors/projections and rebuild consumer documents. Origin, registration and recovery components are opt-in message/value schemas, not HTTP endpoints or authority. See [OpenAPI composition](openapi-composition.md). |

## Observable behavior

| Scenario | Before (v0.6.0) | Released v0.7.0 |
| --- | --- | --- |
| Consumer-admitted self-registration | No distinct package registration/default-Role journey | `RegisterUser` accepts only new User ID/email; trusted nonempty RoleName defaults resolve on every command. Pending User, all Roles, activation/encrypted delivery and audit commit together. |
| Invalid defaults or duplicate email | Private invitation is not a registration substitute | Empty/invalid/missing/conflicting defaults or `ROLE_SUPER_ADMIN` reject; canonical-email claims in every User state and live reservations prevent a second identity or delivery. No package generic success/takeover is introduced. |
| Registration mail, retry and restart | No durable registration-versus-invitation origin | Stored origin reaches the outside-transaction provider across recovery. Provider acceptance leaves the User pending; existing single-use password-setting activation activates that same User with its Roles. |
| Wrong address or expired registration | No distinct administrator registration recovery | Authorized correction, or same-email reissue at/exceeding expiry, retains the User and exact Roles and atomically replaces activation authority/delivery. Changed defaults are not reapplied. |
| Invitation worker failure notification | Storage/subscriber diagnostics could enter failure evidence; a notification fault could mask the original throwable | Constant `User invitation delivery failed.` evidence retains the safe command; the original throwable survives notification failure. This applies to both private invitation and registration. |

Register `InvitationDeliverySubscriber` for `UserRegistered` and `PendingRegistrationRecovered` when immediate dispatch
is wanted. Independently schedule protected due discovery and direct workers: events are not reliable recovery storage.
Delivery remains **at-least-once**, not exactly-once. Retry/reclaim uses the same generation ID; fresh resend/recovery
uses a new ID. Private correction rejects registration; deleted-to-pending restoration starts a new private invitation.

Pre-commit failure rolls back coupled effects. Post-commit notification failure does not undo them. Lost responses or
indeterminate commits require protected authoritative-state inspection, not assumed rollback or blind command replay.
Registration duplicates reject rather than reissue; recovery binds an exact expected predecessor. The detailed
[registration](user-registration.md) and [recovery](registration-recovery.md) guides retain timing, stale-worker,
terminal-delivery and failure limits. Consumers sanitize rethrown diagnostics and protect identity/email-bearing events.

## Evidence and release boundary

Canonical TASK records own implementation evidence and exact accepted candidates. TASK-00075's accepted full package
gate passes **2225 tests / 42779 assertions**, exact **7264/7264 statements**, with no final warnings/skips. Independent
technical Review accepts its candidate; independent nonvisual QA passes all eight selected recovery scenarios without
findings or incompletes. Earlier origin and registration slices also have their own technical acceptance and behavioral
QA. These are controlled package-port proofs, not actual consumer integration.

Independent release-content review accepted prepared candidate `911b5f6`; PR #124 preserved its tree in the actual
main merge. Fresh repository-owned `./bin/release certify 0.7.0` ran on that **exact clean release merge commit**,
passing **2225 tests / 42779 assertions**, exact **7264/7264 statements**, with no final warnings/skips. Planning
validated **111 records / 0 active**; separate OpenAPI consumer composition passed **15 tests / 614 assertions**.
This was a fresh final-commit run, not certification inferred from a previous gate or an identical tree.

The signed local tag, remote tag, GitHub publication and Packagist source/dist projection have each been verified.
Installed-consumer verification remains unexecuted. Later documentation reconciliation does not move the published
tag or recertify a different commit; preserved receipts own each observed outcome.

## Unexecuted consumer qualification

Before enabling registration, consumers must qualify their actual administrator authorization and every dispatch/query
entry point; shared-connection transactions, canonical email/reservation and Role-reference fences, complete-state
hydration/CAS, parallel writers and rollback; physical restart/uncertain commits; keys/ciphers; scheduler and provider
idempotency; independently configurable private-invitation and registration templates; and public enumeration,
throttling/challenges, logging and response sanitization. Package tests model these contracts with controlled adapters.

No page, route, SQL adapter, mail renderer, installation setting, public abuse policy, anonymous recovery or pending-User
cancellation is supplied. Consumer dependency adoption, data operations, enablement and deployment are separate decisions;
publication of this package does not qualify or authorize any of them.
