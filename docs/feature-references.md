# Feature declarations and discovery (unreleased)

[TASK-00061](../planning/tasks/00061-TASK.md) supplies portable name-only metadata and inventory contracts. It does
not itself implement Feature storage, provisioning, availability checks, management or runtime enforcement.
[TASK-00062's atomic provisioning](feature-provisioning.md) consumes this contract, and
[TASK-00063's preparation validation](feature-preparation.md) checks complete candidate references against stored
Features and bound Permissions. Runtime evaluation, management and integrity guards remain TASK-00064 through
TASK-00067.
The declarations are an additive public PHP API, not a release, supported partial feature-flag system or consumer
qualification. Declaration/discovery alone changes no persisted format, existing signature or OpenAPI schema;
provisioning has its own storage/schema assessment. [Preparation validation](feature-preparation.md) adds a
separate candidate query. No HTTP endpoint is implied.

## Name and declaration

`Fight\AccessControl\Domain\AccessControl\Feature\FeatureName::fromString()` accepts 1–128 ASCII characters:
lowercase letters/digits separated by single hyphens, starting with a letter. Examples: `a`, `dashboard`,
`new-checkout`, `agent-tools-v2`, `a-1`. `toString()` preserves the input. Empty/overlong names, uppercase,
leading digits, whitespace (including trailing newlines), non-ASCII characters, underscores, repeated/trailing
hyphens and normalization lookalikes throw `Feature\Exception\FeatureNameException`. Nothing is trimmed or converted.

Use `Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag` on a method:

```php
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

final class Checkout
{
    #[FeatureFlag('new-checkout')]
    public function submit(): void
    {
        // Consumer-owned operation; this Attribute does not intercept calls.
    }
}
```

One method declaration carries one name. The Attribute is not repeatable and cannot target a class, property,
constant, parameter or function. PHP rejects wrong targets/repetition when reflection calls `newInstance()`;
merely reading `getArguments()` does **not** validate native placement or multiplicity. The instance exposes
`getName(): FeatureName`, with no status, testing Permission, principal, configuration or any/all composition.
A consumer must instantiate every discovered declaration and reject invalid metadata, not silently skip it.

## Explicit registration and composition

The Application `Feature\FeatureReferences` collection is immutable. It deduplicates by validated name, retains
first-reference order for inspection, and offers no Feature settings or authority. Set membership, not declaration
count, determines which Features are referenced. An ordinary collection alone claims no discovery completeness.

```php
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferences;

// Illustrates reflection on one already-selected method, not a complete production scanner.
$method = new ReflectionMethod(Checkout::class, 'submit');
$names = [];
foreach ($method->getAttributes(FeatureFlag::class) as $attribute) {
    $names[] = $attribute->newInstance()->getName();
}
$discovered = new FeatureReferences(...$names);
$registered = FeatureReferences::fromStrings('agent-tools-v2', 'new-checkout');
$combined = $discovered->merge($registered);
// $combined->getNames() contains new-checkout, agent-tools-v2 (FeatureName values).
// Neither input changes. Every supplied string validates, even among duplicate references.
```

Supported programmatic checks must be registered. Dynamic unregistered checks are unsupported. Consumers must
inventory all supported declaration paths; the package cannot discover an intended check a developer never declared.
Invalid raw registrations throw `FeatureNameException`; typed construction accepts only `FeatureName` values.

## Complete discovery versus failed or incomplete discovery

Consumers implement `Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery`:

```php
public function discover(FeatureReferenceScope $scope): FeatureDiscoveryResult;
```

`FeatureReferenceScope`, `FeatureDiscoveryResult` and `FeatureReferences` are in the Application `Feature` namespace.
The consumer selects the exact intended code and merges all its discovered and explicit references before returning:

- `FeatureDiscoveryResult::complete($scope, $combined)` only after complete, successful discovery.
- `FeatureDiscoveryResult::unavailable($scope)` for failed or incomplete discovery. This result accepts no partial
  names. Propagating a discovery/validation exception is also valid; callers must stop, not substitute an empty set.

A fully scanned code inventory with no references is a valid complete result using `new FeatureReferences()`.
It is not equivalent to a scanner failure, inaccessible module, malformed Attribute, invalid registration or
unfinished inventory. There is no fallback to “complete empty,” and no scanner diagnostics are carried in results.
Consumers may retain safe diagnostic details privately under their own logging policy.

Callers explicitly require the correct scope when extracting references:

```php
use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;

function candidateReferences(FeatureReferenceDiscovery $discovery): array
{
    return $discovery->discover(FeatureReferenceScope::CANDIDATE)
        ->getReferences(FeatureReferenceScope::CANDIDATE)
        ->getNames();
}
```

`getReferences($requiredScope)` throws Domain `Feature\Exception\FeatureDiscoveryException` for unavailable
results or scope mismatch. Provisioning/preparation uses `CANDIDATE`; future reference-safe deletion must require `CURRENT`.
The two scopes are not interchangeable: candidate code can remove a reference while currently running code still
uses it. Never relabel a result or reconstruct it under another scope to bypass that check. A fresh discovery of the
correct code is required. Consumers own code identity, scan coverage, explicit registry completeness and freshness;
these enums do not attest a deployment revision, fence running processes or certify undisclosed code. No filesystem
scanner, cache, environment access, database, framework integration or deployment coordination is supplied.

## Effects and follow-on boundaries

Declaration, registration, merging and reading discovery results are pure metadata operations. They perform no
Permission lookup/grant, persistence, transaction, event dispatch or access decision. A `FeatureFlag` declaration
cannot authorize a caller, enforce business Permissions, provision a Feature or prevent a method being called.
Consumers own scanning, composition, runtime enforcement, persistence and deployment wiring. TASK-00062 consumes
candidate discovery for provisioning; TASK-00063 adds [preparation validation](feature-preparation.md). TASK-00067 owns current-reference
deletion. Declaration/provisioning does not discharge those remaining guards.

## Executable package evidence

- `tests/Domain/AccessControl/Feature/FeatureNameTest.php`: exact name boundaries and rejection without normalization.
- `tests/Application/AccessControl/Feature/Attribute/FeatureFlagTest.php`: actual PHP reflection instantiation,
  invalid name/targets/repetition and direct method invocation without implied enforcement. The deliberately invalid
  `Fixture/InvalidFeatureTargets.php.fixture` is loaded by PHP during those tests; it is not valid consumer source
  for static analysis. Runtime execution, not source matching, proves rejection.
- `tests/Application/AccessControl/Feature/FeatureReferencesTest.php`: discovered/explicit merge, duplicate identity,
  immutable inputs and returned arrays, empty collections and invalid registration rejection.
- `tests/Application/AccessControl/Feature/FeatureDiscoveryResultTest.php`: both scopes, complete empty/nonempty,
  partial/empty failed or incomplete scans, invalid native/explicit metadata and wrong-scope rejection.

The controlled test discovery implementation scans an already-selected object; it does not prove a real codebase
was fully scanned or that a consumer selected the correct running deployment. Consumer qualification must exercise
its own scanner, invalid declarations, inaccessible code, explicit registry, code identity and candidate/current
selection before treating discovery as evidence. No screenshots are needed for these nonvisual PHP contracts.
