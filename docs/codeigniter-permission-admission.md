# Optional CodeIgniter 4 Permission admission

TASK-00106 supplies `Adapter\Http\CodeIgniter\PermissionFilter`, `RequestContexts`, `SafeErrorResponder` and
an explicitly selected `SafeCodeIgniter` loop. They reuse the [shared inward admission contract](http-permission-admission.md)
and Common's safe HTTP policy. CI4 is optional; nothing registers services, routes, filters, authentication, grants or
a global exception handler. TASK-00179 connects portable Permission attributes to the existing filter through
`PermissionRequirements` and the shared native `MatchedAction` resolver. CI3 and arbitrary PHP interception are not supplied.
The separately enabled [Feature filter](codeigniter-feature-admission.md) consumes the same generic bridge.
The combined five-framework release remains unfinished and unversioned.

## Current verification boundary

TASK-00179 now selects `SafeNativeLogger` before native formatting/cache/handlers, delegating to the **same**
Common `SafeHttpDiagnostics` owner as `SafeErrorResponder`. Correction to the earlier Work hold: CI4 supports an
explicit logger service replacement; a thin ingress to the existing owner is not a competing reporter. The loop
alone still cannot intercept swallowed native failures. Select both services as shown below; no vendor change or
native Attribute safeguard disablement is needed. TASK-00179 has independent Review ACCEPT and nonvisual QA PASS
for this package boundary; actual consumer composition remains separately qualified.

The separate fixture mistake is corrected: before native test bootstrap, the Permission fixture defines its own
`WRITEPATH` under ignored `var/tests/task-00179/native-permission/<process>/`. Native logs, file cache, sessions,
debugbar and uploads use that owned output tree, not installed dependencies. Native logging and required filters
remain enabled. The earlier canonical input-stability failure remains a failed historical receipt; relocating test
output alone did not resolve the production reporting gap or establish acceptance. Production-style native proof
now uses separate PHP processes with `ENVIRONMENT=production`, native Common loaded before Composer helpers and
`Boot::bootConsole` followed by the selected web loop. Default required filters and native `pre_system` buffering
remain enabled. This does not certify a deployed front controller or persistent worker.

TASK-00168 removed the affected Feature bootstrap-dependent journeys; those tests remain removed. Its
[Feature verification limits](codeigniter-feature-admission.md#current-verification-boundary--task-00168-amendment)
are unchanged. TASK-00179 separately requires native Permission proof. `NativePermissionTest` now exercises real CI4
routing, default required filters, aliases, before-filter short-circuit, inherited/method/array/invokable/closure
controllers, User/Agent authority and the selected native response/error loop. Controlled units separately qualify
portable decisions, cooperating gates, fresh context and finite response/fallback behavior. Native cases are classified
Integration, not Unit coverage. These fixtures do not qualify installed consumer routes, authentication, storage,
cryptography, sinks, worker reset or deployment. Earlier supplier evidence retains its historical subject.

## Trusted declaration and native filter wiring

Use explicit PHP routes. Attributed class/method actions need only the registered filter; no redundant route
Permission is required:

```php
use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;

#[RequiresPermission('REPORT_READ')]
final class ReportAction extends \CodeIgniter\Controller
{
    public function handle(string $id): string
    {
        return 'Consumer-owned action';
    }
}

$routes->get('reports/(:num)', '\\App\\Controllers\\ReportAction::handle/$1', [
    'filter' => ['permission', 'protectedValidation']
]);
```

For closures, explicit public/authenticated routes or additional protected requirements, use one trusted
`PermissionDeclaration` custom option keyed by its class name:

```php
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

$routes->setAutoRoute(false);
$routes->get('reports/(:num)', '\\App\\Controllers\\ReportAction::handle/$1', [
    'filter' => ['permission', 'protectedValidation'],
    PermissionDeclaration::class => new PermissionDeclaration(
        AdmissionMode::PROTECTED,
        [PermissionName::fromString('REPORT_READ')]
    )
]); // Illustrative consumer-owned Permission, not an installed grant.
```

`PUBLIC` deliberately has no requirements and resolves no authority. `AUTHENTICATED` requires complete selected
authentication but no Permission. `PROTECTED` requires every distinct canonical Permission in a nonempty list.
The shared constructor rejects malformed lists, duplicates and mode conflicts. Missing/null declarations, raw names,
foreign objects or arrays of declarations fail as configuration errors, not public access, unless a missing/null
route declaration is supplemented by effective attributes. `PermissionRequirements` validates every class/method,
repeatable, inherited/interface and overridden-parent attribute before deduplication. All distinct requirements,
including protected route additions, accumulate; a child cannot erase a parent requirement. Explicit PUBLIC or
AUTHENTICATED-only conflicts reject before authentication. An absent attribute is not PUBLIC. The shared
`MatchedAction` binds composition to the actual matched native class/method or exact closure without controller
construction or another scanner. Two routes sharing a callback retain separate requirements. No alias argument, URL parameter,
header, role label or copied SecurityContext/Permission list can replace these trusted options.

Construct one injected `PermissionFilter` with:

- A trusted `Closure(): Router` returning **the current native router used by this dispatch**, not a retained router
  from an earlier request. Options are read only after native matching. Auto-routing has no explicit matched route
  and is not supported by this integration. Do not load route options from untrusted/deserialized input.
- One `RequestAuthentication($authorityFactory, $contexts)` shared by cooperating Feature/Permission filters.
  Its trusted `Closure(IncomingRequest): ?RequestAuthority` returns null only for genuinely absent selected auth.
  Create fresh actual User or Agent providers per dispatch using the shared guide's composition. Choose exactly one;
  never try an alternate identity after failure. Bind signed Agent context to the actual method, authority, path,
  normalized query and body under consumer-owned proxy/canonicalization rules. Opaque factory/storage/configuration
  faults remain server faults. There is no issuer, default credential or role-to-Permission conversion.
- The same `RequestContexts` supplied to `RequestAuthentication`, and the selected `SafeErrorResponder`.
  Construct `PermissionFilter($currentRouter, $authentication, $safeErrorResponder)`; downstream code reads
  `$contexts->get($request)` using the exact current Request object. This is an admitted short-request snapshot, not
  an ambient principal, propagation API, target-scope grant or authority for later live/background work.

CI4's `Filters::createFilter()` constructs aliases with **zero arguments** and caches their instances. It does not
inject this package's constructor dependencies. Supply a consumer-owned zero-argument shell that delegates to the
configured service; do not point the alias directly at the injected package class:

```php
final class PermissionFilterShell implements \CodeIgniter\Filters\FilterInterface
{
    public function before(\CodeIgniter\HTTP\RequestInterface $request, $arguments = null)
    {
        return service('permissionAdmissionFilter')->before($request, $arguments);
    }

    public function after(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        $arguments = null
    ) {
        return service('permissionAdmissionFilter')->after($request, $response, $arguments);
    }
}
```

Here `permissionAdmissionFilter` is a **consumer-defined Config\Services factory**, not an installed service ID.
It returns the injected package filter described above. Register the shell as `Config\Filters::$aliases['permission']`.
Use current `Config\Feature::$oldFilterOrder = false`. The qualified route filter list runs admission before the
protected validator. Unknown aliases and nonempty admission arguments fail safely; `permission:public` is not policy.
Every selected route needs explicit registration and either effective attributes or a deliberate route declaration.
Public/authenticated-only routes require their explicit declaration.
An omitted filter is outside coverage, not automatically protected. Consumers audit actual registration.
Do not reroute, replace the request/controller or mutate route options after admission without a new qualified check.

## Native order and lifetime

The selected native sequence is required-before filters → routing/route-attribute discovery → ordinary before
filters → controller-attribute before hooks → controller construction/init → action → ordinary after filters →
controller-attribute after hooks → remaining native processing → required-after filters/post-system/send.
A returned `ResponseInterface` from the before filter skips later ordinary validators, controller construction and
action. Denial is an actual native short circuit, not a PSR-15 response. The package after hook performs no auth.

Qualified targets are a fully qualified `\\ClassName::method/$1` route string, `[ClassName::class, 'method']`,
`\\ClassName::__invoke/$1` and a closure, with actual captured route parameters. Already constructed objects, service
aliases, `_remap`, auto-routing, magic/static callbacks and arbitrary route loaders need separate qualification.
Native route-attribute discovery can instantiate attribute metadata **before** admission; no promise covers those
constructors or earlier bootstrap/required/global filters. Controller construction after the filter was historically
proved for these selected forms. Native validation and `Restrict` remain additional independent rejection boundaries.
Direct methods, buses, workers and use cases still enforce their own authorization and record/relationship scope.

The filter/responder/context map may be reused. A new router or repeated gate clears that exact request's previous context
before selection; failed admission leaves none. PUBLIC admission selects no authentication and preserves only a
cooperating gate's admitted context in the same dispatch, never an incoming copied context. Providers must be request-owned, never cached across requests.
One selected provider supplies the immutable snapshot for all requirements/cooperating downstream work: no per-filter
Agent HMAC/nonce replay. Fresh independent requests need fresh authentication/nonces. The weak-key context map does not
keep requests alive and never selects a parent context for another object. Retaining a completed request does not turn
its snapshot into future authority; re-enter admission before reuse.

CI4's service registry, router, filters and native response are request-scoped. The consumer must call
`$authentication->clear($incomingRequest)` at dispatch entry before routing/required filters, including when reusing
a request object: a routing failure may never reach this filter. Controlled units cover fresh package state on repeated and distinct request objects. Native bootstrap/reset
qualification for an installed persistent worker remains external. Actual worker bootstrap/reset
is the consumer's duty; this is not certification of a long-running server. **Reentrant/nested CI4 dispatch sharing the
process-global service registry or the same mutable request is not supported.** No native subrequest API or implicit
caller delegation is supplied. Distinct independent request-context isolation is tested, not concurrent nested loops.
Later protected continuation uses the [current-authority owner](agent-continuation-authorization.md) separately.

## Selected Common-backed JSON error boundary

The selected runtime flow has one redaction owner:

```text
CI4 routing/native log_message -> SafeNativeLogger ----> Common SafeHttpDiagnostics -> consumer redacted sink
Permission filter/loop -------> SafeErrorResponder ----> same diagnostics owner
                                      |
                                      +--> Common policy/envelope -> fresh CI4 JSON response
SafeNativeLogger sink failure -> selected loop -------> unreported safe fallback
```

This is explicit consumer composition, not an installed global service or a second error policy.
A before-filter catch does not wrap downstream actions. CI4's ordinary `Config\Exceptions::handler()` also runs
**after** `Debug\Exceptions::exceptionHandler()` logs raw messages/causes/traces when logging is enabled. Selecting
that handler alone is not a redaction boundary. This package instead offers an explicit native loop subclass:

```php
// In the consumer's Config\Services, preserving CI4's shared-service convention:
public static function codeigniter(?\Config\App $config = null, bool $getShared = true)
{
    if ($getShared) {
        return static::getSharedInstance('codeigniter', $config);
    }

    return new \Fight\AccessControl\Adapter\Http\CodeIgniter\SafeCodeIgniter(
        $config ?? config(\Config\App::class),
        service('ordinaryJsonErrors'),
        service('logger') // The same SafeNativeLogger selected below.
    );
}
```

`ordinaryJsonErrors` is another consumer-defined factory returning `SafeErrorResponder($appConfig, $diagnostics,
$projector)`. Supply the **same responder** to the filter and **same diagnostics object** to the logger. For example,
add this explicit factory to the consumer's `Config\Services` (the IDs below are consumer-defined):

```php
public static function logger(bool $getShared = true): \Psr\Log\LoggerInterface
{
    if ($getShared) {
        return static::getSharedInstance('logger');
    }

    return new \Fight\AccessControl\Adapter\Http\CodeIgniter\SafeNativeLogger(
        service('ordinaryHttpDiagnostics')
    );
}
```

CI4 also supports explicit runtime `Config\Services::override('logger', $selectedLogger)` before selected routing;
the production fixture qualifies that registration, not testing-only logger injection. Pass that exact logger to
`SafeCodeIgniter` as its third argument. Do not compose it as an additional native handler: native interpolation
and cache happen before handlers. The ingress never calls native `Logger::log` or forwards to raw sinks.
Common's `SafeHttpDiagnostics` remains the sole reporting owner: it must redact before any downstream logger/formatter,
and must not forward raw Throwables as PSR exception context or log back through this ingress. Production redaction
and sink delivery still require consumer proof; the package does not supply a redactor.

A record with an actual `context['exception']` Throwable reports that exact object. Otherwise the owner receives
`NativeDiagnostic`: a generic diagnostic, no recovered exception/cause, an uninterpolated nullable native string
message (at most 4,096 bytes), and at most 16 inspected context entries retaining only string keys/values (keys at
most 128 bytes, values at most 4,096 bytes). Arbitrary Stringable messages become null; objects, nested context,
numeric keys and non-string values are not converted. These fields are **private diagnostic input**, not public
response metadata or pre-redacted data. Level/text/code never determines HTTP status. Records are reported immediately,
not retained or deduplicated by text. Malformed Permission metadata can produce a native diagnostic and a separate
package failure through this one owner. An independent native Attribute failure that CI4 swallows remains a diagnostic;
it does not invent an admission refusal or promise that the native hook ran successfully.

Sink exceptions do not escape the logger as routing control. Recursive logger entry is stopped and marked failed.
The selected loop replaces its result with the responder's finite, unreported generic fallback after a reporting
failure and resets the failure flag at entry/exit, including exceptions. This is not rollback or pre-action cancellation:
CI4 may continue after a swallowed native diagnostic. Consumers must not depend on a broken optional Attribute for
required authorization. Do not reuse this mutable ingress across concurrent/reentrant loops. No global handler
replacement, logging disablement or implicit service takeover is installed. Native `log_message` in **testing** mode
bypasses service selection with `TestLogger`; this production selection does not protect that testing-mode path.

`SafeCodeIgniter::handleRequest()` delegates the native implementation, catches failures before native 404 override/
global handling, discards partial buffered output and invokes that responder. This covers routing, ordinary before
filters, controller-attribute hooks, construction/init, validation, action, ordinary after filters and remaining
`handleRequest()` work. Handled filter denial is already a Response and is not reported again. Current Permission native cases exercise routing failure and downstream action failure after partial output,
with one diagnostic attempt and a finite decoded safe response. Supplier units retain after/render/fallback checks;
installed consumer loggers, earlier hooks and explicit action logging still require separate qualification.

**Outside this boundary:** startup, pre-system events, required-before filters, required-after filters, post-system,
response sending/streaming, shutdown, fatal resource exhaustion and other code outside `handleRequest()`. Earlier
loggers and explicit action logging are not undone. Preserve native safeguards and qualify their error owners; do not
remove security filters to obtain this ordering. Any required-before page-cache hit that returns protected content
needs independently qualified admission/cache partitioning; it precedes this filter. Required-after/response code can
still mutate or fail a safe response and needs consumer qualification. A successful action followed by failure is not
rollback. Redirect/Responsable and native HTTP exceptions are not silently translated by code or message.

The filter/loop use Common's actual `SafeHttpErrorPolicy`, `SafeHttpPresentation`, `SafeHttpMetadata`,
`SafeHttpProjector`, `SafeHttpDiagnostics` and `JSendEnvelope`. Only actual known User/Agent refusals are translated by
shared RequestAuthority; incomplete/opaque Agent snapshots and provider faults remain operational 500. Missing
Permissions produce PermissionDenied/403. Native CI4 PageNotFound is unknown to this Common policy and deliberately
gets generic **500**, including unmatched methods/routes, rather than guessing 404 from its code. Domain failures
remain Domain-owned. A projector changes presentation, not classification. Unknown exceptions with a 401/403 code or
known cause remain 500.

A 401 needs a consumer-selected valid challenge; there is no universal Bearer scheme. Valid challenge/Allow/Retry-After
metadata is allowlisted by Common and must apply to the classified type. Missing/invalid metadata, sink/projector/
encoding/primary native response failure falls back to Common's fixed generic 500. Each responder-handled failure has one reporting attempt before projection; distinct native diagnostics may also
reach the same owner. Sink failure is neither delivery nor recursively retried reporting. Responses are fresh/empty, so earlier
challenge, action headers, cookies and partial body are not copied. An optional response factory must preserve that
freshness. If even Common fallback rendering/factory fails, a fresh native Response returns only fixed literal generic
JSON/500, without recursive reporting, original Throwable, metadata or unsafe global-handler escape. Valid App/native
bootstrap and sufficient resources remain prerequisites for constructing that final emergency response.

Common v1.4.0's native JSend helper passes an already encoded string to `setJSON(..., true)`. CI4 4.7.6 interprets true
as **unencoded input**, producing a JSON string rather than an envelope object. It is not appropriate for this bridge:
we use Common's finite `JSendEnvelope::toJson()` with native `setJSON($encoded)` (default false). No supplier is patched,
no second semantic policy is introduced. Native response failure, nonfinite/deep/object/invalid-UTF8 projections and
both fallback factory/render failures had native integration proof, including decoding the actual final body.
Current direct units retain controlled response-port encoding/failure checks. Permission native cases additionally
exercise the selected application lifecycle, including diagnostic/projector/nonfinite projection failure. These do
not establish automatic non-JSON or consumer sink safety.

This is an explicitly selected **JSON-only ordinary HTTP path**, regardless of Accept, including HTML preference or
no Accept. It does not claim automatic HTML sanitization or negotiation. Non-JSON applications keep their separately
qualified native loop/error composition. Keep MCP/OAuth on independently composed entry points outside this loop and
filter: PUBLIC is not a protocol exclusion. No JSON-RPC concealment, OAuth challenge/envelope or scope contract changes.

## Qualified minimum and remaining consumer duties

Current package Common minimum is `^1.5.1`. TASK-00179's isolated exact-minimum composition uses tagged Common
v1.5.1 and CI4 v4.7.6, running the new native Permission cases without Laravel, Slim, Symfony HttpKernel or Yii
application runtimes. Ordinary installed composition runs the same cases. A separate no-dev core fixture proves
portable User/Agent admission and denial with all five framework runtimes absent. Exact identities, locks,
commands, counts and advisories belong to the canonical TASK-00179 Work handoff. TASK-00146 requalifies CI4
categories/privacy and native ordering alongside the other present peers; see
[ordinary HTTP contracts](ordinary-http-failures.md). Current default tests retain CI4 units and the newly required Permission native cases, not the removed Feature
native application journeys.
The identities/counts below preserve TASK-00106's original qualification, not today's installed lock.

At that checkpoint the ordinary installed lane and isolated exact-minimum lane used tagged **Common v1.4.0**
(`87b9580c072f3149d574e09865daa79620c92118`, range `^1.4`) and **CI4 v4.7.6**
(`d755dca632526b116e4611eea9e303505fa6889a`, range `^4.7.6`). These are selected sufficient minima, not guessed oldest
compatible releases. CI4 is a dev fixture plus optional Composer suggestion, never a production framework requirement.
Laminas Escaper 2.18.0 is the native transitive dependency. Installed Common src/manifest and CI4 system/app-config/
manifest bytes in both lanes match upstream tag archives; exact locks, source manifests and tag refs are retained in
TASK evidence. The isolated native suite passes with Slim/Yii/Symfony application runtimes absent. A separate no-dev
core fixture proves real User/Agent admission with CI4 and those other application frameworks absent.

Executed runtime: patched PHP **8.5.12**, Xdebug **3.5.3**, with actual `intl` and `mbstring`. CI4 requires intl; the package
Docker tooling image now installs it. Composer's emulated platform remains resolver metadata, not executed-runtime
proof. Semver ranges are support bounds, not certification of future releases/every resolver combination.

Mirrored Adapter tests, exact owned-statement coverage, PHPCS/PHPStan/Rector/architecture/default OpenAPI tests and the
canonical full gate remain mandatory. Controlled native fixtures are not installed consumer bootstrap/route-order/
worker/authentication/persistence/cryptography/proxy/scope/sink/cache/deployment qualification. Independent technical
Review and behavioral QA are required; no adoption, release or deployment is authorized by these package checks.
