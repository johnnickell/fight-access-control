# CodeIgniter 4 Feature runtime view

Existing implementation at `07719c1d40f59c52dc40c47ea0be30ff5e4997c9`.
The following documentation-only update pins this unchanged source revision and rendered caption. The canonical
Work handoff records the final candidate and its tested-content bridge. This bounded view supplements the
[owning composition guide](../codeigniter-feature-admission.md); it is not a whole class/dependency graph.

![Bounded CI4 Feature admission runtime flow](runtime-flow.png)

[Editable Python](runtime-flow.py) · [Open PNG](runtime-flow.png)

Dotted arrows are numbered runtime interactions, **not static dependency directions**. Layer ownership remains
Adapter → Application → Domain. The error box compresses response propagation, not a new inward error dependency.

| Arrow | Inspected source | Meaning |
| --- | --- | --- |
| 1 | [FeatureFilter::selectedAction/before](../../src/Adapter/Http/CodeIgniter/FeatureFilter.php), [FeatureRequirements::getReferences](../../src/Application/AccessControl/Feature/FeatureRequirements.php) | Consumer clears state before native routing; matched code and route-bound effective requirements must agree before authority/work. No controller is constructed for metadata. |
| 2 | [RequestAuthentication::begin/authority](../../src/Adapter/Http/CodeIgniter/RequestAuthentication.php) | Private request/router identity clears earlier attempts and selects one actual request provider across cooperating gates. |
| 3 | FeatureFilter::before → RequestAuthority::resolve → [FeatureAdmission::admit](../../src/Application/AccessControl/Feature/Admission/FeatureAdmission.php) | Compressed ordered hop to a concrete User/Agent snapshot or deliberate anonymous null, never a copied grant. |
| 4 | FeatureAdmission::admit → [FeatureAvailability::isAvailable](../../src/Application/AccessControl/Feature/Service/FeatureAvailability.php) | All effective requirements use fresh authoritative Feature settings and exact stored Permission bindings. |
| 5 | FeatureFilter::before, [controlled filter units](../../tests/Adapter/Http/CodeIgniter/IsolatedFeatureFilterTest.php) | Null permits later native validators/construction/action; a native Response short-circuits them. Independently selected Permission admission must also pass. |
| F | FeatureFilter::before, [SafeCodeIgniter::handleRequest](../../src/Adapter/Http/CodeIgniter/SafeCodeIgniter.php), [SafeErrorResponder::respond](../../src/Adapter/Http/CodeIgniter/SafeErrorResponder.php) | Ordinary Feature unavailability alone becomes generic 404. The unchanged Common-backed bridge owns one safe report/response and downstream interception, not the before-filter catch alone. |

Omitted: provider authentication/HMAC/nonce stores, repository internals, detailed Common fallback, full Permission
flow, earlier/bootstrap/required filters, send/shutdown, global reentrant loops, non-JSON entry points and installed
consumer composition. Source traces were inspected; an arrow is not execution, acceptance or security proof.
TASK-00168's [coverage amendment](../codeigniter-feature-admission.md#current-verification-boundary--task-00168-amendment)
removes the native application fixture and bootstrap-dependent integration tests. The unchanged diagram describes
intended runtime wiring, not current native journey proof. Controlled units do not prove route/alias conversion,
short-circuit/controller order, required filters, downstream interception or bootstrap reset. Earlier independent
native reports remain historical. Rendering uses local Python/Pillow with no network or
application effects; source and PNG are maintained together.
