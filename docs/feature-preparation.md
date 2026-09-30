# Feature preparation validation (unreleased)

[TASK-00063](../planning/tasks/00063-TASK.md) adds a read-only candidate preparation query and reusable package-port
scenarios. A successful check establishes that *every discovered name* currently resolves to a valid stored Feature
and its original bound Permission ID. It does not activate code, decide feature availability, authorize any action or
certify a consumer deployment. An OFF Feature is valid preparation; it is not available at runtime.

## Consumer composition

Use native, method-only, nonrepeatable `#[FeatureFlag('name')]` declarations and explicit registration for other
checks. The consumer scans the exact **candidate** code, instantiates every Attribute to validate it, merges all
references and attests completeness through `FeatureDiscoveryResult::complete(CANDIDATE, $references)`. A complete
empty inventory is distinct from failed/incomplete discovery. See [declarations](feature-references.md). Supply the
same consumer-composed `FeatureReferenceDiscovery`, `FeatureRepository` and `PermissionRepository` contracts used
by [atomic provisioning](feature-provisioning.md); implement repositories against the target environment's
**authoritative** catalog. Discovery and fresh catalog reads must not reuse stale negative lookup or ORM identity-map
snapshots. Do not substitute a provisioning result or event for validation.

```php
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

// Consumer-owned candidate discovery and authoritative repository implementations.
$handler = new ValidateFeaturePreparationHandler($candidateDiscovery, $featureRepository, $permissionRepository);
$result = $handler->handle(QueryMessage::create(new ValidateFeaturePreparation()));
if (!$result->isPrepared()) {
    // Block activation and surface safe diagnostics to authorized operators.
    $issues = $result->getIssues();
}
```

The query registers through `queryRegistration()` and serializes to/from `{}` (no default, inventory, caller or
scanner serialized in the message); nonempty query payloads reject rather than being silently ignored. Results expose `isPrepared()`, `getIssues()` and `toArray()` with `prepared` and
`issues` fields. Each issue has the validated name and one of `missing_feature`, `broken_binding` or
`invalid_definition`. These are configuration diagnostics, not arbitrary stored bytes, secret material, Permission
names, or transport errors. An existing OFF/PREVIEW/ON Feature with an existing matching bound Permission ID passes
regardless of principal membership. A newly created Permission with a reused name and different ID cannot repair a
broken binding. The handler visits all distinct discovered names without default-page truncation. It neither calls
`getByName()` on Permission nor falls back to `DEFAULT_FEATURE_PERMISSION`.

Failed/incomplete or wrong-scope discovery throws `FeatureDiscoveryException`; a scanner may also throw. Invalid
persisted definitions are reported as `invalid_definition`: repository adapters must surface malformed hydrated
Feature/Permission definitions as `FeatureStateException`, not null, while preserving the affected reference name.
A repository returning a mismatched Feature name or Permission ID is also invalid. Operational repository failures
propagate, not become a successful result or a missing-record diagnostic. Protect arbitrary infrastructure error
messages in consumer presentation/logging; only configuration issue fields are intended for safe diagnostics.
Neither a failed query nor an invalid result authorizes activation. The consumer authorizes setup/read entry points,
retries only after fixing the failure and rereads the target catalog.

## Preparation sequence and limits

1. Discover the complete candidate inventory; run `ProvisionFeatures` with the configured default if creation is
   needed. It performs **one transaction per pass** and leaves existing choices unchanged. A failed pass rolls back
   its inserts; a lost commit response or post-commit publication failure does **not** establish rollback.
2. Retry provisioning against fresh authoritative storage when needed; resolve concurrent creation conflicts
   without replacing winners. A changed default affects only subsequently created records. Neither a normal command
   return nor a `FeatureCreated` notification proves that existing definitions are valid.
3. Run `ValidateFeaturePreparation` freshly against the exact candidate and target catalog. Only after a prepared
   result may the consumer consider activating that code under its own deployment policy; runtime enforcement and
   ordinary action authorization remain separate. This is an observation, not a distributed transaction/fence against
   later privileged writes, undisclosed running code or arbitrary deployments.

The consumer owns scanning/code identity, configuration, persistence/schema/uniqueness and reference fences,
transaction implementation, deployment hooks, runtime checks, UI and entry-point authorization. Package fixtures
model a complete scan and transactional writes but qualify **no real scanner, database race, framework, application
or deployment**. A Feature reference cannot safely outlive removal of its bound Permission: all supported removal
paths and their real race proof remain [TASK-00065](../planning/tasks/00065-TASK.md); actual rebinding/management
remains TASK-00066 and guarded deletion/reintroduction TASK-00067. [Runtime evaluation](feature-availability.md)
is implemented in TASK-00064, pending independent review. There is **no supported partial Feature release** before
management/integrity capabilities and complete Feature acceptance.

## Reusable scenario and compatibility inventory

`tests/Application/AccessControl/Feature/Conformance/FeaturePreparationConformance.php` and its
`FeaturePreparationEnvironment` contract define consumer-bindable scenarios for declared/registered complete or
incomplete inventories, empty/no-default preparation, OFF/PREVIEW/ON and broken bindings, full authoritative
reference reads, atomic-pass rollback, competing creation/winner preservation, fresh retry and post-commit
publication failure. `InMemoryFeaturePreparationConformanceTest` exercises them with controlled package repositories
in the default gate. Consumers bind their own adapters and scanner with disposable data and prove actual transaction,
uniqueness/reference fencing and code identity separately. This suite does not provide production adapters.

[TICKET-00015](../planning/tickets/00015-TICKET.md) traceability: TASK-00061 proves strict name/Attribute/registration
and completeness/scope (`FeatureNameTest`, `FeatureFlagTest`, `FeatureReferencesTest`,
`FeatureDiscoveryResultTest`); TASK-00062 proves creation/default/uniqueness/rollback/winner/uncertainty and
publication behavior (`FeatureTest`, `FeatureMessagesTest`, `ProvisionFeaturesHandlerTest`,
`FeatureComponentsTest`). This TASK adds `ValidateFeaturePreparationHandlerTest` for every fresh read and rejection,
plus the executable composed conformance above and generated schema checks. It does **not** retroactively prove
those preceding slices or close the TICKET's downstream Permission-removal and actual deletion/reintroduction
acceptance under TICKET-00017. No consumer integration proof is claimed.

The new PHP query/result/issue types and opt-in `Fight.AccessControl.ValidateFeaturePreparation`,
`Fight.AccessControl.FeaturePreparationResult` and `Fight.AccessControl.FeaturePreparationIssue` OpenAPI components
are additive public contracts. They create no endpoint, HTTP response, management CLI, production adapter or
release-version decision. Consumers adopting Features must implement authoritative reads and map query failures to
safe operator-facing diagnostics; the package makes no migration/backfill promise for previous iterations.
