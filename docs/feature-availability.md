# Feature availability (unreleased)

[TASK-00064](../planning/tasks/00064-TASK.md) adds a framework-neutral, read-only `FeatureAvailability` service. It accepts a validated `FeatureName` and an existing `AuthenticatedUserPrincipal`, `AuthenticatedAgentPrincipal`, or `null` for anonymous access. Consumers resolve authentication first, enforce the decision at each supported registered check, and separately authorize the underlying action. A true result is **not** action authorization or permission to activate a candidate deployment. No authentication provider or command bus is involved.

```php
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;

$availability = new FeatureAvailability($featureRepository, $permissionRepository);
if (!$availability->isAvailable(FeatureName::fromString('new-checkout'), $principalOrNull)) {
    // Consumer denies the feature-gated path; it still owns action authorization.
}
```

The service fetches the Feature by exact name and its testing Permission by **stored ID on every call**. Both repository adapters must read authoritative committed state without stale ORM identity-map, result, or negative-lookup caches, including a second check in the same request and another job on the same worker. The Feature repository must hydrate invalid persisted definitions as `FeatureStateException`, not as absence; the Permission repository must not return a different ID for the stored binding. No default Permission, discovery pass, provisioning, transaction, write, event, or repair occurs during evaluation. The consumer must first register supported checks through `#[FeatureFlag]` or explicit registration; metadata alone does not enforce a check, and code scanning does not run during evaluation.

| Stored setting | Result after valid binding check |
| --- | --- |
| OFF | `false`, even for privileged Users or Agents |
| PREVIEW | `true` only when the captured User Role-derived or Agent direct Permission IDs include the stored bound ID; anonymous is `false` |
| ON | `true` even for anonymous callers; the underlying action may still require authentication and authorization |

A missing Feature throws `FeatureNotFoundException`; an existing Feature whose bound Permission is absent throws `FeatureBindingException` at **all** statuses. A mismatched repository-returned name/ID or invalid stored definition throws `FeatureStateException`. Invalid names reject through `FeatureNameException`. Repository outages propagate unchanged as operational errors, never a boolean or configuration absence. Consumer transport adapters may conceal broken bindings like OFF with a 404 while retaining safe internal diagnostics; do not map a storage outage to 404 or expose arbitrary exception messages. No HTTP endpoint or serialized evaluation payload is supplied by this library.

The supplied principal remains an immutable snapshot. A renamed Permission retains its ID and still matches; another Permission with the same name and a different ID never matches or repairs a deleted binding. Protected Permissions remain human-only under existing assignment rules; neither a super-admin Role name nor an Agent's display name is a bypass. Fresh Feature settings do **not** refresh grants or revocations inside the already-resolved principal; use the consumer's normal principal lifecycle for later requests. An OFF transition affects subsequent explicit checks after it is visible to the authoritative read. Work admitted without another check can finish; this library does not schedule rechecks, cancel jobs, or atomically coordinate a check with later work.

`tests/Application/AccessControl/Feature/Conformance/FeatureAvailabilityConformance.php` and `FeatureAvailabilityEnvironment` offer consumer-bindable public-port scenarios for same-instance status/binding changes, worker reuse, in-flight checks, unknown Features and broken identity bindings. The default in-memory binding proves package behavior only. Real adapters must qualify fresh ORM/database reads and the consumer must separately test scanning, registration, protected entry-point enforcement, authorization, UI and workers. This additive PHP service and exception contract creates no new OpenAPI component, transport schema, production Adapter or backwards-compatibility path. No partial Feature release, consumer qualification or deployment is claimed; [Permission reference guards](feature-permission-references.md), [management](feature-management.md) and [retirement](feature-retirement.md) are separate package operations. The [complete evidence inventory](feature-evidence.md) retains their scoped acceptance and consumer limits.
