# v0.10.0 release and integration overview

**Published on 2026-11-19 (UTC).** The [immutable GitHub release](https://github.com/johnnickell/fight-access-control/releases/tag/v0.10.0)
uses the signed `v0.10.0` tag at certified `main` merge `ae1cd27340a97daf260bbe30ae95d2fc62826453`
([PR #263](https://github.com/johnnickell/fight-access-control/pull/263)). [Packagist](https://packagist.org/packages/johnnickell/fight-access-control#v0.10.0)
source and archive references match that commit. The tag preserves the pre-publication snapshot.
PHP 8.5+ and Fight Common ^1.5.1 are unchanged.

## System attribution for trusted Role assignments

`AssignRoleToUser` and the post-commit `RoleAssignedToUser` event accept `UserId` or `SystemId` as the actor. The target is still a `UserId`. A configured audit writer records the system actor inside the existing transaction. An audit-write failure rolls back the assignment without publishing a success event.

### Update consumers that assume a User actor

Both `getActorId()` methods now return `UserId|SystemId`. Check the returned type before passing it to anything that requires `UserId`; do not convert a system actor into a nil User ID. Existing User constructors and serialized payloads are unchanged. The new system payloads use `actor_kind: system` with the canonical nil `actor_id`. Without the discriminator, even a nil UUID represents a User actor.

`SystemId` is attribution, not authorization. The consuming entry point still owns caller admission and must enforce the actual operation, target and bootstrap restrictions before dispatch. No CLI authentication, role eligibility, or role removal behavior changes. See [User membership actors](role-membership-contracts.md#user-membership-actors).

## Verification and consumer obligations

Package tests exercise the v0.9.0 User payloads, system round trips, mapped audit evidence, rollback on audit failure and event ordering. They do not qualify a consumer's CLI admission or production audit persistence. Consumers must test their real trusted entry points and verify that configured audit storage shares the assignment transaction. Certification and registry verification do not establish installed-consumer behavior; no consumer installation was tested.
