# Bound public registration disclosure and abuse

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Optional user self-registration for v1.0.0](../self-registration-v1-map.md)
**Depends on:** WF-027

## Question

What public response and abuse-control responsibilities must accompany a consumer-exposed registration operation without making AccessControl enforce whether an installation enables registration?

## Must decide

- Existing duplicate canonical-email rejection remains unchanged: claimed addresses and live reservations reject the new registration, with no new activation work. No package-owned generic acknowledgement or silent-success behavior is requested. Any public response policy is a consumer choice, not a replacement for internal rejection.
- Throttling, bot challenges, public-request logging and response mapping remain with the consumer at every exposed entry point; the package does not add a registration-specific anti-abuse policy beyond authoritative uniqueness and atomicity.
- Consumers decide their public failure presentation and assess enumeration at the transport boundary without changing package exception/rethrow semantics. Administrator registration recovery is an authorized separate operation, never a public duplicate-email fallback.

## Required evidence

- [WF-026](WF-026-registration-admission-and-default-authority.md) puts registration exposure with consumers and requires atomic canonical-email uniqueness. [WF-027](WF-027-registration-delivery-origin.md) defines durable registration mail and distinct administrator recovery. `UserRepository::add()` rejects canonical-email/reservation conflicts atomically; `InvitePendingUserHandler` rethrows and dispatches `CommandFailedEvent`, with no success publication or delivery on duplicate. Its test expects `DuplicateEmailException` across User lifecycle states. John explicitly rejected changing this behavior. No package HTTP response exists; proposed generic public acknowledgement was an unapproved suggestion, not current behavior.

## Resolution boundary

Decide public safety responsibilities and observable invariants. Leave HTTP statuses/copy, CAPTCHA vendor, infrastructure rate-limit configuration, framework response code and actual consumer load/abuse testing to implementation/integration guidance. Do not reopen consumer-owned enablement, default Roles or delivery origin.

## Resolution

**Settled by John:** Duplicate emails remain rejected as today. Do not convert a duplicate into package-level success or send another activation email. Registration maintains atomic uniqueness and surfaces the failure internally; administrator registration recovery is a separate authorized path, not anonymous reuse of an existing User. A generic public acknowledgement was only a proposed consumer response and is **not** an accepted requirement. Throttling, challenges, abuse logging and public response mapping belong entirely to each consumer exposing the use case, across HTTP and any other entry point; no new package enablement or anti-abuse policy is required. Consumers must explicitly evaluate their own public disclosure/abuse risks before exposure, rather than assuming package behavior conceals account existence. This closes WF-028; no consumer response/abuse verification is claimed.
