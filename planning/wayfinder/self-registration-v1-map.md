# Wayfinder Map: Optional user self-registration for v1.0.0

**Label:** `wayfinder:map`
**Status:** Closed

> This map is an **index, not a store**. Material decisions live in the linked tickets.

## Destination

Plan an opt-in AccessControl project track for public User self-registration before the package's v1.0.0 stability decision. A consumer such as the CMS may enable a Register User page per installation, create a pending User with a configured default Role set (typically `ROLE_USER`; `ROLE_SUPER_ADMIN` is prohibited), and send a registration-specific email containing a one-time password-setting/activation path. Existing private invitations remain distinct. Consumer projects own message templates and mail rendering, including private invitation messages.

**Done** = admission/default authority, registration-origin delivery and public-request safety decisions are closed, any remaining fog is resolved or excluded, and the map links its approved EPIC planning destination(s). Implementation, consumer enablement and v1.0.0 certification are later operations.

## Notes

- John requests this as an optional **package** capability before v1.0.0; fight-agent-os and starters may lock their v0.5.0 feature set without enabling it. This request does not hold up or amend the v0.5.0 release preparation. v1.0.0 timing and release authority remain separate.
- [TICKET-00001](../tickets/00001-TICKET.md) explicitly excluded public self-registration from the original lifecycle. That completed requirement remains historical authority for existing invitations, not an obstacle to a separately planned additive track.
- Current source: `InvitePendingUserHandler` atomically creates a pending User, activation grant, encrypted delivery and `user.invited` audit; `InvitePendingUser` includes actor, User ID and email, not registration mode or default Roles. `User::invite()` starts with an empty Role set; `AssignRoleToUserHandler` performs a separate Role assignment. `AuthenticationService::activate()` sets the initial password and activates a pending account. These are possible reuse seams, **not** a design decision to expose the invitation command to anonymous callers.
- Delivery currently passes purpose `activation`, delivery ID, destination and sensitive credential through `CredentialDeliveryInvocation` to the consumer-owned `CredentialDeliveryProvider`. No package-owned email template/rendering contract appears at that boundary. No delivery-origin context currently survives to choose a registration-specific template across immediate dispatch, retry/resend, and scheduler recovery; John has now approved adding it to the durable delivery/provider contract. [Credential delivery guide](../../docs/credential-delivery.md) documents the persistent recovery contract and consumer provider composition.
- John settled exposure: consumers decide whether registration is offered/used, including installation database settings; AccessControl provides registration use-case commands, queries and handlers but does not enforce an installation-enable check. Consumers protect every entry point they expose. John chose construction-time consumer configuration of a RoleName string list; the registration handler resolves current Roles by name for automatic assignment, without a Role choice in the public command. John requires a nonempty default-Role list, whole-registration rejection on a missing Role, and no `ROLE_SUPER_ADMIN` default. He approved atomic pending User/default Roles/activation grant/delivery/audit creation with canonical-email and Role-reference safety; email invocation remains post-commit. Do not assume `ROLE_USER` exists or accept Role authority from anonymous input.
- Starting evidence: clean `develop` at map creation (`git status --short --branch` reported `## develop...origin/develop`). Preserve unrelated work and recheck source/release state at each handoff.

## Decisions so far

1. John chose an **optional** self-registration track, absent from the default Agent OS/starter feature sets for now. Enabling it is installation-dependent, with default Role(s) and registration-specific email messaging.
2. Email templates belong to the **consumer project**, for both existing private invitations and new registration mail; package code must not take ownership of rendered messages.
3. John rejected a package-enforced enablement gate: a consumer controls the Register User page and use of the new use-case operations. AccessControl must make private invitation and public registration distinguishable to the consumer mail provider.
4. **[Define registration admission and default authority — WF-026](tickets/WF-026-registration-admission-and-default-authority.md) is settled.** Consumer-configured nonempty `RoleName` strings at handler construction are resolved against the authoritative Role repository; the public request does not select Role authority. A missing Role or `ROLE_SUPER_ADMIN` rejects the whole registration. Pending User, all default Roles, activation grant/delivery and audit commit atomically under canonical-email uniqueness and current Role-reference safety, or none commit.
5. **[Distinguish registration mail from private invitations — WF-027](tickets/WF-027-registration-delivery-origin.md) is settled.** Registration reuses activation but persists an immutable registration/private origin with recoverable delivery and passes it to the consumer provider for template choice across recovery/resend. Distinct administrator registration recovery corrects a wrong address or reissues after expiry at the same email, retaining the pending User and Roles while invalidating old authority. Restoring a deleted User to pending is a new private-invitation journey, even if originally registered. Consumer provider adapters/templates remain their own qualification obligation.
6. **[Bound public registration disclosure and abuse — WF-028](tickets/WF-028-public-registration-safety-boundary.md) is settled.** Duplicate emails remain rejected internally as today; no package generic success or second activation email. Consumers own throttling/challenges, abuse logging, public responses and exposure-specific disclosure assessment.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-026 | [Define registration admission and default authority](tickets/WF-026-registration-admission-and-default-authority.md) | Grilling | HITL | **Closed** | — |
| WF-027 | [Distinguish registration mail from private invitations](tickets/WF-027-registration-delivery-origin.md) | Grilling | HITL | **Closed** | WF-026 |
| WF-028 | [Bound public registration disclosure and abuse](tickets/WF-028-public-registration-safety-boundary.md) | Grilling | HITL | **Closed** | WF-027 |

## Blocking relationships

```text
WF-026 settled → WF-027 settled → WF-028 settled → [EPIC-00013](../epics/00013-EPIC.md) written
```

## Frontier

No Wayfinder decision remains: [WF-026](tickets/WF-026-registration-admission-and-default-authority.md), [WF-027](tickets/WF-027-registration-delivery-origin.md) and [WF-028](tickets/WF-028-public-registration-safety-boundary.md) are Closed. John approved the sole destination; [EPIC-00013](../epics/00013-EPIC.md) is written. John approved TICKET-00021–00023 and TASK-00073–00075; all planning phases are complete. Consult the Board for separately authorized execution selection.

## Not yet specified (fog)

No additional product-policy decision is presently required for the proposed destination. Exact API/event names, admin authorization contract, Role-reference fencing and conformance matrices belong to the EPIC/TICKET design; real consumer database/mail qualification is not package proof. Consumer default Role names are configured at handler construction, resolved for each new registration and persisted with that User; administrator recovery retains the existing Role set, not a retroactive reconfiguration.

## Out of scope

- Turning registration on by default in fight-agent-os or starters, or implementing a CMS page, database configuration adapter, mail templates or transport in this package.
- Reworking private invitation policy by default, allowing anonymous callers to choose their Roles, or treating a delivery attempt as proof of activation.
- A new public cancellation flow or broad non-pending account recovery, reconfiguring already-pending Users when consumer Role defaults change, or changing duplicate-email failures into generic package success. Consumers may assess public enumeration and decide their own response mapping.
- Changing the v0.5.0 scope, tagging/signing/publishing v1.0.0, consumer adoption or deployment as an effect of this map.

## Planning handoff

**Approved and written:** [EPIC-00013 — Optional User Self-Registration and Registration-Origin Delivery for v1.0.0](../epics/00013-EPIC.md).

**John confirmed this title and boundary:** One AccessControl package destination covers opt-in consumer-wired registration operations, atomic configured default Roles/identity/activation/delivery/audit, durable registration-versus-private-invitation mail origin, administrator pending-registration correction/expired reissue and unchanged duplicate rejection. The package supplies no Register User page, enablement check, transport abuse controls or templates. Package acceptance must prove both mail origins across retry/restart/reissue. Separately, actual consumer composition must check that existing private invitation templates are configurable and distinct from registration templates; no consumer qualification is claimed here.

John approved this one destination after WF-026–028 closed, then Grill wrote EPIC-00013 and closed this map. John subsequently approved its three-area requirement split: [TICKET-00021](../tickets/00021-TICKET.md) (delivery origin), [TICKET-00022](../tickets/00022-TICKET.md) (registration admission) and [TICKET-00023](../tickets/00023-TICKET.md) (administrator recovery). John approved one TASK for each TICKET: [TASK-00073](../tasks/00073-TASK.md) (origin) → [TASK-00074](../tasks/00074-TASK.md) (registration) → [TASK-00075](../tasks/00075-TASK.md) (administrator recovery). The EPIC/TICKET/TASK planning handoff is complete; this map authorizes no implementation. The Board owns execution priority.
