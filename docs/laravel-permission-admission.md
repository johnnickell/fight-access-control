# Native Laravel Permission admission (Unreleased)

`Adapter\Middleware\Laravel\PermissionMiddleware` is an explicitly selected native route middleware. It delegates to
shared inward `PermissionDeclaration`, `RequestAuthority` and `PermissionAdmission`; it is not a PSR-15 wrapper,
a guard, a grant issuer, a Feature gate or a portable Permission Attribute implementation. See the
[shared admission contract](http-permission-admission.md) for deliberate PUBLIC/AUTHENTICATED/PROTECTED modes,
User Role-derived versus Agent direct Permissions and separate target-scope authorization.

## Install and select deliberately

The qualified combination is Common `^1.4.1`, Laravel framework `^13.40.0`, Symfony HttpFoundation/HttpKernel
`^8.1.11` and Event Dispatcher `^8.1.5`, on patched PHP 8.5.12. Laravel is a dev fixture and optional suggestion,
not a required production framework. A consumer selecting Laravel installs and maintains its native components:

```sh
composer require laravel/framework:^13.40.0 symfony/http-foundation:^8.1.11 symfony/http-kernel:^8.1.11
```

Common 1.4.0 is explicitly excluded: its Laravel fallback can throw when fallback encoding itself fails, causing
recursive native reporting and private renderer-fault escape. Common 1.4.1 supplies the terminal native response;
AccessControl adds no competing handler, logger or renderer. Installing this package activates neither middleware
nor error handling and does not replace consumer bootstrap, authentication, routes, persistence or diagnostics.

## Bind exact trusted routes and actions

Build bindings **after** the actual serving routes have been registered/loaded, before serving requests. Bind the
same native Route objects used by the Router, not copies, route names, callback names or request attributes. A
private definition-only WeakMap associates each Route with its original `uses` action and explicit declaration.
Unknown/unbound routes, a missing/changed action, duplicate bindings and malformed/missing declarations fail closed
as configuration faults. Two routes using the same callback do not share a declaration automatically.

The following is consumer composition, not a package service provider. `$app` is the actual Laravel Application,
`$router` its Router, `$kernel` its HTTP Kernel, and `$authorityForRequest` a trusted request factory described below.
The controller and Permission names are consumer-selected examples, not new package grants.

```php
use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Illuminate\Routing\Middleware\SubstituteBindings;

$protected = $router->get('/profile', $profileController)
    ->middleware([PermissionMiddleware::class, SubstituteBindings::class]);
$public = $router->get('/public', $publicController)->middleware(PermissionMiddleware::class);

$app->instance(PermissionMiddleware::class, new PermissionMiddleware(
    $router,
    [
        [$protected, new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ')
        ])],
        [$public, new PermissionDeclaration(AdmissionMode::PUBLIC)]
    ],
    $authorityForRequest
));
$kernel->prependToMiddlewarePriority(PermissionMiddleware::class);
```

Use actual protected route groups/controller middleware as appropriate; merely registering bindings does not
attach middleware. Every protected entry must execute it. Do not remove/exclude it, disable native middleware,
change routes/actions while serving or assume direct controller/bus calls pass through routing. Native priority
must put admission before protected binding/validation/dispatch. Authentication selection that must precede it
is separately composed by the consumer; the prepend example assumes the factory obtains trusted authentication
without depending on a later route middleware. Never move protected business work into earlier authentication,
route-matching events, constructors, middleware discovery or bootstrap.

Route caching serializes/reconstructs routes, not the middleware's WeakMap or factory. Rebuild bindings from the
actual loaded serving RouteCollection on each application boot; this slice does not qualify compiled route-cache,
resource-route generation or hot route replacement. No route-name lookup or permissive cache-miss fallback exists.

## Trusted authority and request lifetime

The factory has the contract `Closure(Illuminate\Http\Request): ?RequestAuthority`. Select **one** trusted path:

- For a User, create a fresh `CurrentPrincipalProvider` using trusted `AuthenticationContextProvider` claims and
  `AuthoritativePrincipalResolver` with current User/session/Role/Permission repositories and clock; return
  `RequestAuthority::user($provider)`.
- For an Agent, obtain a trusted `SignedAgentRequest` bound to the actual method, authority, path, normalized query
  and body/digest, and create a fresh `CurrentAgentPrincipalProvider` with real verifier/decipher, current
  Agent/Permission repositories, nonce consumer and transactional boundary; return
  `RequestAuthority::agent($provider, $signedRequest, $correlationId)`.
- Return null only for intentional absence. Provider/configuration/storage failures throw; never convert an opaque
  failure into anonymous/public access or a fallback User/Agent guard. Integer codes, role labels, administrator
  names, OAuth scopes, copied Permission arrays, request attributes, headers and route parameters are not grants.

PUBLIC skips authority selection and clears any copied context; AUTHENTICATED requires a valid selected authority
without a Permission requirement; PROTECTED checks every declared Permission conjunctively. A missing declaration
is not PUBLIC. Shared inward admission maps only deliberate authentication rejection to AuthenticationRequired;
incomplete Agent Permission snapshots and opaque resolution/storage faults remain server errors.

On every middleware entry, the request's `SecurityContext::class` attribute is removed before checking target or
selecting authority. Successful non-public admission sets a new immutable snapshot there. The middleware may be
reused, but its factory must not close over a cached principal/request/provider singleton. Multiple Permissions
share that request's single authentication/nonce consumption. Reusing the same signed nonce on a later request
fails; the adapter does not reauthenticate for each Permission or replay an original nonce for continuation.

Sequential reused-container/request journeys, exception recovery and distinct nested native `Kernel::handle()`
requests are exercised. Each nested request receives its own trusted selection, not the parent's context; the
parent request object's admitted snapshot remains unchanged. Laravel's container request, router current route and
facades are process-global mutable state: these tests do **not** promise automatic ambient-state restoration after
nested dispatch or concurrent/coroutine safety. The consumer owns native request-stack/sandbox restoration and
must not use a nested request's ambient state to authorize parent work. Do not recursively dispatch the same Request
object or treat an already admitted object as an authority lease. Subsequent pages/disclosures/background work need
[current continuation checks](agent-continuation-authorization.md) and independent scope checks at their real owner.

## Supported native order and construction limits

Actual Kernel/Router fixtures exercise closure actions, `Class@method`, `[Class, method]`, invokable classes,
static `Illuminate\Routing\Controllers\HasMiddleware` and legacy `Illuminate\Routing\Controller` middleware.
Controller middleware alone is also exercised for the latter two forms; requirements still come from explicit
trusted Route/action bindings, not inferred controller attributes or unvalidated middleware string arguments.

| Form / stage | Qualified ordering |
| --- | --- |
| Closure, ordinary method-string/array and invokable controller without legacy discovery | Denial before bindings, validator, controller construction, FormRequest authorization/rules, method provider, action and bus work |
| Static HasMiddleware | Discovery executes static middleware metadata before admission; instance construction/action are not reached on denial |
| Legacy Controller/getMiddleware | Native discovery constructs the controller **before admission**, even if Permission middleware is also route-attached; constructor is not protected |
| Admitted FormRequest journey | Admission → binding → validator → construction → form authorization/rules → method provider → action/bus → final response |

Static metadata code, native attribute constructors, earlier listeners/global middleware and arbitrary eager container
resolution are not authorization proof. This slice does not implement portable class/method RequiresPermission or
Feature metadata. Keep constructors/discovery free of protected side effects. Consumers qualify their exact native
forms, middleware groups/priority, parameter binding, caches and alternative/direct/worker entry points.

## One Common-owned safe reporting and rendering boundary

Explicitly select the actual `Fight\Common\Adapter\Http\Laravel\SafeLaravelExceptionHandler` for
`Illuminate\Contracts\Debug\ExceptionHandler`. Bind `SafeHttpErrorPolicy`, **one redaction-aware**
`SafeHttpDiagnostics` implementation and, optionally, `SafeHttpProjector` before resolving that handler:

```php
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Laravel\SafeLaravelExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;

$app->instance(SafeHttpErrorPolicy::class, new SafeHttpErrorPolicy());
$app->instance(SafeHttpDiagnostics::class, $redactingDiagnostics);
$app->singleton(ExceptionHandler::class, SafeLaravelExceptionHandler::class);
```

`$redactingDiagnostics` is consumer-owned and must redact exception messages, causes, traces, credentials and private
payloads before any external formatter/logger. A sink interface alone proves no redaction. Do not add raw reportable
callbacks, a second native/PSR renderer, an unsafe legacy fallback or another reporting subscriber. On the selected
native JSON path Common reports once per Throwable object before raw report methods/callbacks/context/default
formatters, even normally ignored Laravel failures. A failed sink is one **unsuccessful attempt**, not successful
delivery; it is never retried and skips projection. A reused Throwable remains deduplicated; separate failures on
later requests remain reportable.

Common translates supported native Laravel/HTTP types and applies shared type policy. Known refusals are distinct
from opaque provider/storage/configuration failures; codes/messages/causes/projector guesses do not select status.
A consumer projector receives the original failure and classified status after reporting, and may supply only
intentionally public finite output. It cannot turn an unknown type into 401/403. Never echo raw Throwable text.

- A 401 requires a valid **consumer-selected** WWW-Authenticate challenge (for example Signature), never a universal
  Bearer assumption. Missing/bad challenge yields safe 500, including native AuthenticationException.
- Supported native Allow and canonical decimal-string Retry-After metadata are validated; duplicate/misapplied/invalid
  headers fail safe. Arbitrary exception headers are not copied. A 405 requires Allow. Application retry hints remain
  private unless explicitly enabled in the selected policy.
- Object/nonfinite/deep/invalid-UTF-8 projection, malformed metadata, sink/projector/primary encoder faults use generic
  500 fallback. Common 1.4.1 additionally contains fallback encoding failure with fixed pre-encoded native JSendResponse
  bytes: `{"status":"error","message":"Internal server error."}`, Content-Type application/json, no selected error
  headers. It bypasses failed collaborators/encoder rather than recursively reporting a new private renderer fault.

## Negotiation, final response and limits

The handler selects the safe JSON path using actual Laravel `expectsJson()`: JSON-preferred Accept and XHR with
wildcard Accept are exercised; HTML-preferred, explicit HTML XHR and missing Accept delegate to Laravel. That
**non-JSON delegation is not a sanitizer**: consumer render callbacks, exception render methods and debug HTML may
run. Choose a JSON-only endpoint contract or independently qualify safe HTML composition; do not claim this package
makes arbitrary HTML/debug routes safe. Request debug flags do not switch the selected JSON handler to raw output.

Actual RequestHandled final header processing, native response type/getData, prepare/sendContent, persistent encoder
fault and later independent failure/success are exercised. Process death/OOM, arbitrary final middleware/observer/
send/shutdown faults, external server/proxy behavior and consumer diagnostics are outside that proof. No package
handler can sanitize untrusted response changes after it returns. Keep MCP JSON-RPC/Tools and OAuth protocol entry
points/envelopes separate from this ordinary HTTP/JSend composition.

## Tagged minimum evidence and consumer qualification

These are selected sufficient tagged lower bounds, not guesses about the oldest possible compatible versions:

| Component | Tested minimum source |
| --- | --- |
| Common v1.4.1 (`^1.4.1`) | `a482c220d312d2ef7762c3f0d85d9fb76a7021fa` |
| Laravel v13.40.0 (`^13.40.0`) | `1449bf241017087fbd38c118ecaabef3f6797fdc` |
| HttpFoundation v8.1.11 (`^8.1.11`) | `30fa3efe6e736b0d06f5331cc6abe813582c0a26` |
| HttpKernel v8.1.11 (`^8.1.11`) | `460658504e26a2794aa614b4fbe6cf9bac2076fe` |
| Event Dispatcher v8.1.5 (`^8.1.5`) | `7458da64220376b2e0dc2d8451bf43382c1ad297` |

Common's annotated remote v1.4.1 tag peels to the installed source above; its runtime/test/manifest bytes equal the
independently accepted release-preparation candidate. Supplier acceptance is not consumer proof. The original
AccessControl v1.4.0 persistent-fault regression red is preserved, and the unchanged probe passes on installed 1.4.1;
the owning native regression now runs in default coverage. Exact locks, archive/source equivalence and executed
runtime/reflection evidence live in TASK-00105's Work handoff.

The ordinary installed lane and isolated exact-minimum native lane both exercise real User/Agent admission and the
native suite. The isolated lane has no Slim/Yii/CI4 application runtime or Symfony Security HTTP. Symfony HTTP
primitives are Laravel dependencies, not absent-framework claims. A production-only core lane exercises inward
admission/optional adapter autoload with Laravel absent. Other transitive dependencies are lock-recorded, not an
exhaustive all-lowest matrix. Preliminary HttpFoundation/HttpKernel 8.1.9 passed behavior but reported five newly
published security advisories; it is **not** the supported floor. 8.1.11 qualification retains a clean Composer audit.
PHP 8.5.12/Xdebug 3.5.3 is actually executed; Composer platform 8.5.4 is resolver metadata, not a minimum-patch test.
Semver ranges are support bounds, not qualification of all future versions or every resolver combination.

The default native fixtures select one application bootstrap per process. Laravel's eager global helpers (notably
`config()`) collide with CI4's different globals if both applications are booted together. The package test launchers
(`bin/phpunit` and `bin/quality`, used by `bin/build`) prepend `tests/Adapter/framework-bootstrap.php` before Composer
to defer only Laravel's Foundation/Support global helpers. Laravel tests run in fresh processes and load those actual
supplier files from the selected framework source before native boot. CI4's source/fixtures and production behavior
are unchanged. This is test isolation, not support for running two application bootstraps in one PHP process.
Direct raw `vendor/bin/phpunit` is not the qualified all-framework launcher; focused native commands must retain the
same prepend option. Independent minimum tests also use the actual isolated vendor and these process boundaries.

Mirrored Adapter tests, exact owned-statement coverage and the full package gate remain binding; independent Review
and behavioral QA are still required. Controlled native fixtures use real inward contracts with modeled repositories,
nonce transactions and fixed crypto boundaries, not actual consumer authentication/persistence/cryptography. Consumers
must qualify real bootstrap/guards/routes, complete target binding, order/construction, service reset/nesting, nonce
atomicity, storage/crypto, scope/grants, safe sinks/projectors, proxies, workers and deployed runtime before adoption.
The combined all-five-framework release remains unfinished; no release, adoption or deployment is authorized here.
