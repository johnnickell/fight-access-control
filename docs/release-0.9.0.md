# v0.9.0 release and integration overview

**Published on 2026-11-18.** The [immutable GitHub release](https://github.com/johnnickell/fight-access-control/releases/tag/v0.9.0)
identifies signed tag `v0.9.0` at certified merge `b2d2c8a0fe041a0d950df4d12daa0b9e44892808` from
[PR #261](https://github.com/johnnickell/fight-access-control/pull/261). Packagist source and distribution references
match that commit. Consumer installation was not tested. The immutable tag preserves the pre-publication snapshot.
See the [Changelog](../CHANGELOG.md#090---2026-11-18) for the full change inventory.
Some capability guides retain their original “Unreleased” section names so existing links still work. The
v0.9.0 changelog, not those earlier labels, identifies the changes included in this release.

## Before updating a consumer

This is a breaking pre-v1 minor. It requires **PHP 8.5+** and raises the Fight Common floor from `^1.3` to
`^1.5.1`. Update callers and persisted mappings to the current contracts. Under
[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md), the package provides no compatibility defaults,
historical readers, migration/backfill path or data reset. This release does not establish a new support window
or authorize v1.0.0. Composer requires `psr/http-message:^2.0` and `psr/http-server-middleware:^1.0`.
Slim, Yii 3, Symfony, Laravel and CodeIgniter 4 are optional native installations, not core runtime requirements.

| Boundary | What to check or change |
| --- | --- |
| Synchronous User operations | Replace `AuthenticationService` with the direct authentication, session, password and email-confirmation owners. Update named arguments, catches and persistence to the current authority and revision contracts. See [User authentication](user-authentication.md) and [operation outcomes](operation-outcomes.md). |
| Exact email-change intent | Send `email_change_grant_id` for self-service and administrative cancellation. The exact email-change delivery status exposes that request ID; other purposes return `null` for that field. Persist and hydrate the original request origin with the exact grant, even when notification participation is off. See [credential delivery](credential-delivery.md). |
| Invitation and registration recovery | Remove the retired handler authorization ports and constructor arguments. Before trusted dispatch, authenticate the caller and authorize the operation for the exact target at every consumer entry point. See [lifecycle contracts](user-lifecycle-contracts.md) and [registration recovery](registration-recovery.md). |
| Agent issuance and MCP | Register the void `ProvisionAgent` and `RotateAgentCredential` Commands; read original outcomes through authorized Queries rather than returned results or publication warnings. Update Agent operation repository bindings, current imports and MCP registrations. See [Agent integration](agent-integration.md), [profile Tools](agent-profile-tools.md) and [Common contracts](common-contracts.md). |
| Role rename and removal | Keep sending the exact displayed revision. Catch Common Application `PreconditionFailed` for stale intent (refresh and reconfirm); handle `StateConflict` separately for later write/reference conflicts. Do not carry forward the v0.8.0 `RoleConflictException` catch at the Command boundary. See [Role revisions](role-revisions.md). |
| Agent delivery, reads and administration | Return coherent `AgentDeliveryWorkView` rows from the read repository and wire delivery recovery with `QueryBus`. Update administration callers to the precise input types and explicit absence, denial and conflict categories. See [Agent query contracts](agent-query-contracts.md), [delivery recovery](agent-delivery-recovery.md) and [administration contracts](agent-administration-contracts.md). |
| Paged repositories | Return a bounded, ordered, correctly typed page with truthful counts and page metadata; mismatched repository results are internal failures, not deliberate absence. See [paged query contracts](paged-queries.md). |
| Optional atomic audit | Choose audit storage per owner. Omitted/null storage disables durable audit; a configured write failure aborts the coupled outcome. Qualify typed persisted facts and a shared transaction before enabling it; later opt-in does not backfill. See the [audit outcome inventory](audit-outcome-coverage.md) and [evidence mapping](audit-evidence.md). |
| HTTP admission | Select Feature and Permission independently or together. Feature availability does not grant Permission. Use the trusted native action and the documented request lifetime and error handling for Slim, Yii 3, Symfony, Laravel or CodeIgniter 4. CI4 Feature/combined evidence is distinct from Permission-native evidence. See the [framework evidence map](http-permission-admission.md). |
| Wire contracts and OpenAPI | Recompose consumer OpenAPI documents and regenerate clients as needed. Update required-resource callers for typed absence and optional credential status callers for explicit absence. The package ships [schema components](openapi-composition.md), not endpoints. See the [payload catalog](public-payload-catalog.md) and [required-resource Queries](required-resource-queries.md). |

## Verification boundaries

The [Task Board](../planning/tasks/BOARD.md) and individual records distinguish implementation acceptance from
release acceptance. [Release preparation and certification](releasing.md) require independent acceptance, required
QA and certification of the exact merge on `main` before human signing. A dated changelog entry or passing local
build is not evidence of a signed tag, GitHub publication, Packagist availability or consumer installation.

Qualify real persistence mappings and shared transactions, locks and concurrent writers, restart and restoration,
keys and sinks, and entry-point authorization with your own adapters. Check actual routing, error handling and
provider behavior before enabling capabilities. Package fixtures do not qualify those environments or authorize
consumer workaround removal, schema operations or deployment.
