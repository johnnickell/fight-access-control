# Optional CodeIgniter 4 Feature admission (Unreleased)

`Adapter\Http\CodeIgniter\FeatureFilter` restricts the actual matched action before protected ordinary validation,
controller construction and action work. Feature-only registration needs no `PermissionFilter` alias or declaration.
It consumes the shared [effective requirements](feature-references.md) and [Feature admission](http-feature-admission.md),
not another Attribute merger, evaluator, scanner or provisioning path. Availability never authorizes the action.
CI3, auto-routing, direct PHP/bus/worker interception and automatic consumer registration are not supplied.

## Current verification boundary — TASK-00168 amendment

John approved replacing practical CI4 behavior with units and removing the bootstrap-dependent integration tests
from the existing TASK-00168 PR. `FeatureFilterTest`, `PermissionFilterTest`, `SafeErrorResponderTest` and their
unused native application/filter/authority/diagnostic fixtures are removed because their shared-process framework
bootstrap collides with Laravel's global helpers. TASK-00175's earlier native acceptance remains historical.

Current isolated tests exercise package filters with controlled router/request/response ports, real effective
requirements, in-memory Feature/Permission state and concrete authority providers. They cover status/grant decisions,
invalid selection, denial/fault translation, cooperation, selection reuse and context clearing. They do **not** prove
CI4 route conversion, alias registration, native before-filter short-circuit, validator/controller order, required
filters, downstream exception interception or worker/bootstrap reset. Those real framework guarantees now require
separate consumer qualification; the composition below describes intended wiring, not current native test proof.
The controlled native response leaf tests are not an application journey or installed framework qualification.
They still need the existing test launcher's pre-Composer framework symbol selection (as used by `bin/quality`).
Plain Composer-first PHPUnit invocations can still resolve CI4's `config()` call to Laravel's helper; removing the
native application journeys does not remove that limitation. No new bootstrap workaround, production behavior,
global testing-policy change or coverage exclusion is introduced by this amendment.

TASK-00179 separately adds the required [native portable Permission proof](codeigniter-permission-admission.md#current-verification-boundary).
It reuses the same matched-action resolver and error owner, but neither restores the removed Feature journeys nor
qualifies Feature-native bootstrap/ordering. The distinction is deliberate: Permission-native evidence is not
Feature-native acceptance.

## Explicit native composition

Keep explicit PHP routes and `Config\Feature::$oldFilterOrder = false`. Register a zero-argument native filter shell
that delegates to the injected service, exactly as in the [Permission shell example](codeigniter-permission-admission.md#trusted-declaration-and-native-filter-wiring),
substituting `FeatureFilterShell`, `featureAdmissionFilter` and alias `feature`. Those names are consumer-defined,
not installed package services. CI4 caches shells and does not inject the package constructor itself.

```php
use CodeIgniter\Config\Services;
use Fight\AccessControl\Adapter\Http\CodeIgniter\FeatureFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;

$contexts = new RequestContexts();
$authentication = new RequestAuthentication($authorityFactory, $contexts);
$router = static fn() => Services::router(); // The exact current dispatch's router.
$feature = new FeatureFilter($router, $authentication, new FeatureAdmission($availability), $safeErrors);
// Optional, separately registered capability using the SAME authentication/responder instances:
$permission = new PermissionFilter($router, $authentication, $safeErrors);

$routes->setAutoRoute(false);
$routes->get('reports/(:num)', '\\App\\Controllers\\ReportAction::handle/$1', [
    'filter' => ['feature', 'protectedValidation'],
    FeatureRequirements::class => new FeatureRequirements(
        SelectedAction::method(\App\Controllers\ReportAction::class, 'handle'),
        'report-rollout'
    )
]);
```

`$availability` is the existing `FeatureAvailability` with consumer repositories. `$safeErrors` is the existing
`SafeErrorResponder`, also supplied to the explicitly selected `SafeCodeIgniter` JSON loop. `$authorityFactory` is a
trusted `Closure(IncomingRequest): ?RequestAuthority`: choose exactly one real User or signed Agent provider for the
request, or deliberate anonymous null. Use the [actual authority composition](codeigniter-permission-admission.md#trusted-declaration-and-native-filter-wiring).
Do not convert provider failure, conflicting identity or copied `SecurityContext`/grants into anonymous context.
Known authentication refusals retain typed 401 with a valid consumer-selected challenge; opaque faults stay 500.

The selected native router supplies its actual controller/closure and method after matching. `FeatureRequirements`
automatically accumulates class, repeated, inherited/interface, overridden-parent and selected method Attributes.
The optional custom route option adds requirements and verifies exact action identity. Omit that option to use
Attributes alone; absent effective Features means no Feature restriction, not PUBLIC Permission admission.
For closures, bind `SelectedAction::closure($theSameClosure)` explicitly to add route requirements. A different
closure/controller/method, malformed metadata or unsupported target fails safely before authentication/work.
No filter arguments, route parameters, headers or incoming context select policy. `feature:rollout` is invalid.
Do not replace/reroute the action or request after admission without a new qualified check.

## Evaluation and native ordering

Every effective Feature must pass the existing fresh authoritative binding check. OFF denies all callers; PREVIEW
requires the stored exact Permission ID in the concrete User/Agent snapshot; ON passes even anonymously but grants
no action Permission. A binding is validated at every status. Missing definitions, mismatched names/IDs, broken
bindings and metadata/provider/storage/native failures are safe faults, not ordinary unavailability. Only ordinary
OFF/unmet PREVIEW becomes Common `ResourceNotFound` and a generic JSON 404 without Feature/configuration details.
Admission adds no Command, Query, Event, audit, transaction, grant or evaluator policy. Authentication's existing
session/nonce effects remain separate.

Qualified forms are fully qualified `\\Class::method/$1`, `[Class::class, 'method']`, an inherited public method,
explicit `\\Class::__invoke/$1`, and a closure with trusted explicit binding. Metadata reflection does not construct
controllers. `_remap`, static/magic callbacks, service aliases, preconstructed controller objects, arbitrary loaders
and auto-routing are unsupported, not public fallbacks. CI4's native route conversion/attribute safeguards remain.

The actual sequence is required-before → routing/native Attribute discovery → ordinary Feature/Permission filters →
protected validator → native controller-attribute hooks → controller construction/init → action → ordinary after
filters → remaining loop → required-after/send. A before-filter `ResponseInterface` skips the later ordinary
validator/controller/action. Native required filters and `Restrict` are not disabled. Earlier bootstrap, required/global
filters, attribute construction and already eager consumer work cannot be undone. A required-before cache hit needs
independently qualified admission/cache partitioning; merely listing this filter does not protect an earlier hit.

## Shared authentication and lifetime

Both package filters now take the same `RequestAuthentication` rather than separate factories/context maps. It uses
private exact request/router identities, selects a provider once per native dispatch, and preserves cooperating
snapshots even when one gate is PUBLIC or has no Feature declarations. Each gate must be registered once. Either
order retains both restrictions; Feature ON and PUBLIC Permission cannot erase the other restriction. Real provider
snapshot caching gives one Agent HMAC/nonce consumption, not authentication replay per Feature/Permission.

The consumer must call `$authentication->clear($incomingRequest)` at dispatch entry, **before native routing or
required filters**, in addition to resetting CI4's service/router/filter/response registry and selecting fresh
providers/nonces. A routing failure or early required-filter response may never enter either admission filter, so
filter-local clearing alone is insufficient when reusing a request object. The removed native fixture checked a
successful request followed by an unmatched-method failure on that same object. Current units do not prove pre-routing
reset. This is explicit consumer bootstrap wiring, not an implicit global handler or automatic framework reset.

Within that dispatch a new native router or repeated gate begins fresh admission and clears that request's previous
context; filter failures also clear context. The map is weak-keyed; distinct requests never inherit parent authority. Retaining an old request/snapshot does not make it current/live/background authority.
Snapshots do not continuously refresh grants; only Feature settings/bindings are freshly read by explicit checks.

Distinct-request authentication-state nesting is isolated, but **reentrant native CI4 loops sharing process-global
services, or mutating/reusing the same request inside an active dispatch, are unsupported**. This package creates no
native subrequest API or implicit parent handoff. Consumer worker reset and installed native nesting need separate
qualification. Exceptions do not confer authority, and the next dispatch cannot reuse old headers/body/selection.

## One existing safe error owner

Reuse the [selected Common-backed native JSON boundary](codeigniter-permission-admission.md#selected-common-backed-json-error-boundary)
unchanged. A before-filter catch alone does not intercept downstream failures; a native JSend helper alone is not
an exception owner. The explicit `SafeCodeIgniter` loop catches routing through downstream ordinary filter/controller/
action/after-filter failures before CI4's raw global logger/formatter. The same `SafeErrorResponder` reports once to
redaction-aware diagnostics per handled failure, then uses Common's finite policy/projection/encoding and fresh
native response. The linked current recipe also explicitly selects `SafeNativeLogger` to direct native diagnostic
records to that same owner before native interpolation/cache. Distinct native/package events are not text-deduplicated.
TASK-00179 qualifies that production selection on its Permission path; it does not restore removed Feature journeys.
Handled Feature refusal is not reported again by the loop. Partial buffered output and prior action/challenge headers
are not copied. Sink/projector/encoding/native-render/fallback failures retain fixed safe JSON/500 without recursive
reporting or raw fallback. Current bridge units qualify direct package calls and controlled response ports. Removed
Feature-only journeys checked downstream interception and one report without Permission activation; units do not replace
that native lifecycle proof.

This selection is **ordinary JSON-only regardless of Accept**, not automatic HTML negotiation/sanitization. Startup,
pre-system/required-before, required-after/post-system, sending/streaming, shutdown and resource exhaustion remain
outside the loop boundary. Native PageNotFound is unknown to Common and becomes generic 500, not guessed 404 from
its message/code/cause. Consumer sink redaction/delivery, earlier explicit logs and outer failure owners require real
qualification. MCP/OAuth retain separate entry points/envelopes; PUBLIC is not a protocol exclusion.

## Runtime view and qualification boundary

The [source-linked runtime view](codeigniter-feature-admission/runtime-flow.md) traces selection, shared authority,
inward checks and native response short-circuit. It is a bounded view, not acceptance or a whole framework graph.

Sufficient selected minima are current package **Common v1.5.1** and **CI4 v4.7.6**; semver ranges do not certify every
future resolver combination. CI4 remains development-only plus an optional Composer suggestion. The package's
patched PHP 8.5 runtime includes intl and mbstring. Historical native journeys and current isolated Unit coverage
remain distinct: current units neither certify native routing/filter ordering nor replace removed framework proof.
The canonical Work handoff owns exact
ordinary/minimum/core-only locks, versions, counts, failed attempts, warnings and full-gate/coverage evidence.

Controlled fixtures qualify package behavior, not actual consumer route/alias ordering, authentication/HMAC/nonce
storage, repositories/connections, scanner completeness, bootstrap/cache/worker reset, proxies, sinks or deployment.
Independent Review and nonvisual QA remain required. No consumer adoption, combined-family release, version selection,
merge or deployment follows from this guide.
