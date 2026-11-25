# Optional HTTP Permission admission

TASK-00103 supplies route-bound PSR-15 admission, qualified separately with **Slim 4** and **Yii 3** components.
It does not register routes, authentication, grants, containers, error handlers or another framework.
[TASK-00104's Symfony guide](symfony-permission-admission.md) covers the separate optional native post-routing
subscriber and Common exception composition. [TASK-00106's CI4 guide](codeigniter-permission-admission.md) covers native
before-filter admission and its thin Common-backed selected JSON error bridge. The
[Laravel guide](laravel-permission-admission.md) covers its present native middleware. Current typed categories,
validation privacy and all seven native contracts are reconciled in [ordinary HTTP failures](ordinary-http-failures.md).
The combined EPIC release is not yet complete.

TASK-00168's explicit [CI4 coverage amendment](codeigniter-feature-admission.md#current-verification-boundary--task-00168-amendment)
removed the affected bootstrap-dependent journeys and retained isolated package behavior checks. TASK-00179 separately
supplies new [Permission-native proof](codeigniter-permission-admission.md#current-verification-boundary), not restoration
of those Feature journeys. Keep Feature-native/combined lifecycle qualification separate from Permission-native proof.

## Portable Permission admission (Unreleased)

TASK-00176 connects [effective portable requirements](permission-requirements.md) to the existing PSR-15 middleware,
separately exercised through actual Slim and Yii 3 dispatch. `PermissionMiddleware` now takes
`PermissionRequirements`, a trusted `Closure(ServerRequestInterface): SelectedAction`, and the existing authority
factory or shared `RequestAuthentication`. Update callers directly; the former declaration-only constructor is removed.
The middleware validates selected-action association before selecting authentication, then calls existing
`PermissionAdmission`. It adds no merger, policy engine, Feature collaborator, business message or audit write.

Attribute-only actions are PROTECTED. Class/method/repeated/inherited/overridden-parent requirements accumulate;
protected route additions also apply. PUBLIC/AUTHENTICATED route modes conflict with effective Attributes. Raw
route list/type/mode/duplicate validation still runs before effective deduplication. No Attribute and no explicit
route policy remains a configuration fault, not PUBLIC. Closures need an explicit trusted route declaration.
Metadata construction can reject at bootstrap; dynamic target lookup/mismatch faults produce safe 500 before
authentication or protected validation/construction/action. Target lookup cannot manufacture an authentication refusal.

```text
trusted native route/action --> SelectedAction --> PermissionRequirements
                                      ^                     |
                                      |             association check
                                actual selection             |
                                      +----------------------+
                                                             v
request-owned authentication ----------------------> PermissionAdmission --> protected work
```

The native tests retain existing User Role-derived/Agent direct authority, error/header/fallback and lifetime proof,
and add portable Attribute-only User admission, inherited/overridden/invokable Agent conjunction, exact route additions,
metadata fault order and cooperation with Feature gates in either order with one nonce. Shared declaration tests own
raw malformed Attribute/mode validation. These package fixtures do not qualify consumer routes, scanners, bootstrap,
crypto, storage, sinks or target/tenant scope. Independent Review/QA and final cross-framework reconciliation remain
separate; this change activates no other native adapter or consumer application.

## Feature cooperation (Unreleased)

[PSR-15 Feature admission](http-feature-admission.md) now consumes effective declarations independently of this
Permission middleware. Adjacent gates may share one `RequestAuthentication` as their trusted factory/scope; both
restrictions still apply and copied attributes never establish authority. PUBLIC Permission admission continues to
skip authentication, but preserves a context already selected by the cooperating Feature gate. Standalone PUBLIC
admission still erases an incoming copied context. See the Feature guide for exact lifetime and adjacency limits.

The existing [native Laravel Permission middleware](laravel-permission-admission.md) now also consumes portable
requirements under TASK-00178. Bind the actual serving Route with null for Attribute-only protection or an explicit
PROTECTED declaration for additional conjunctive requirements. Its native lifecycle and Common safe handler remain
the owners; this does not imply acceptance of sibling integrations or actual consumer composition.

## Current portable admission reconciliation (Unreleased)

### Five families, independently selected capabilities

Install just the required gate, or both with the **same native RequestAuthentication instance**. Register each
explicitly; an installed class or Attribute alone intercepts nothing. Both restrictions must pass before protected
validation/action work. The first refusal can short-circuit; there is no universal refusal precedence or
arbitrary middleware-order guarantee.

| Family | Feature and Permission wiring | Boundary to qualify |
| --- | --- | --- |
| Slim 4 | [Feature](http-feature-admission.md), [Permission](#slim-composition) | Adjacent gates share the forwarded request. Independent entries clone the qualified PSR-7 request and clear copied context. Use supported lazy forms. |
| Yii 3 | [Feature](http-feature-admission.md), [Permission](#yii-3-composition) | Clone and clear at independent entry; use a fresh Router/CurrentRoute and supported closure/class-method forms. Qualify Yii separately from Slim. |
| Symfony | [Feature](symfony-feature-admission.md), [Permission](symfony-permission-admission.md) | Early request admission precedes lazy construction; the resolved-controller check precedes validation. Native IsGranted and later listeners remain independent. |
| Laravel | [Feature](laravel-feature-admission.md), [Permission](laravel-permission-admission.md) | Bind the exact serving Route/action. Legacy controller discovery may construct early. Distinct child requests need consumer ambient-state restoration; same-request recursion is unsupported. |
| CodeIgniter 4 | [Feature](codeigniter-feature-admission.md#current-verification-boundary--task-00168-amendment), [Permission](codeigniter-permission-admission.md#current-verification-boundary) | Permission-native proof does not qualify Feature-native or combined ordering, bootstrap/reset or downstream interception. Feature cooperation has isolated package checks only. Select SafeNativeLogger and the responder with the same diagnostics owner. |

Qualify actual route/filter coverage, scanner and code identity, target/record/tenant scope, alternate entries,
authentication/signature canonicalization and nonce storage, persistence/cache/locks, bootstrap/worker reset,
diagnostic redaction/delivery, proxies and outer response handling. When combining gates check both orders,
PUBLIC plus Feature, ON plus denied Permission, and one authentication/nonce on the shared request. Fresh Feature
settings do not refresh captured grants. Keep ADR 0010 protected-tier membership and independent Symfony IsGranted;
MCP/OAuth keep their own envelopes. For the package evidence reconciliation, see [TASK-00181](../planning/tasks/archive/00181-TASK.md).

## Inward contract and trusted composition

`Application\AccessControl\Authorization\Admission\PermissionDeclaration` has exactly three `AdmissionMode` values:

| Mode | Requirements | Behavior |
| --- | --- | --- |
| `PUBLIC` | Empty | No authority factory/provider resolution; remove copied SecurityContext, preserving only an active cooperating gate's privately selected context. |
| `AUTHENTICATED` | Empty | Require one complete actual User or Agent authority, without a Permission grant. |
| `PROTECTED` | Nonempty list of distinct `PermissionName` values | Require every named Permission. |

The constructor rejects wrong types, associative arrays, duplicate names, empty protected requirements and
requirements on other modes. Permission names retain their existing canonical validation. Pure optional
[PermissionRequirements](permission-requirements.md) composes portable Attributes using the shared selected-action
traversal. The PSR-15 middleware and optional [Symfony subscriber](symfony-permission-admission.md) now consume it
for the trusted selected native action; remaining portable-native paths retain their own integration owners.
There is no production scanner, route-name allowlist, weakening override rule or implicit public default.
A null route declaration is valid when portable attributes supply the protection. If neither the trusted route
policy nor attributes supply a declaration, fail as a configuration error before authentication. Invalid declarations
normally fail at composition/bootstrap; dynamic composition faults also remain server failures.

Bind a `Adapter\Middleware\Psr15\PermissionMiddleware` instance containing effective requirements and a trusted
selected-action callback **directly to each selected native route**, before validation and action invocation. The native router selects the route; the bound
instance is its exact association, independent of callback identity. Two routes sharing a callback may have different
requirements. Headers, route arguments, request attributes and copied principal/Permission arrays cannot select or
replace the declaration. Every ordinary route must deliberately install its admission instance, including explicit
public routes. A route with no middleware is outside this adapter's coverage, **not** automatically protected.
Audit actual consumer registration; this package cannot detect an omitted registration in another application.

A standalone authority factory has signature `Closure(ServerRequestInterface): ?RequestAuthority`. It is **trusted
consumer composition**, not a request-supplied callable. Return null for genuinely absent selected authentication.
Otherwise choose exactly one of:

- `RequestAuthority::user($currentPrincipalProvider)`: fresh `CurrentPrincipalProvider` with a trusted
  `AuthenticationContextProvider` and actual `AuthoritativePrincipalResolver`. User/session validity and complete
  role-derived Permission resolution remain with those existing services.
- `RequestAuthority::agent($currentAgentPrincipalProvider, $signedRequest, $correlationId)`: fresh
  `CurrentAgentPrincipalProvider` and the actual signed-request/authentication composition. Direct Agent Permissions
  remain distinct from User Roles. There is no administrator/role-label bypass or OAuth-scope conversion.

Neither factory accepts a publicly constructed principal or Permission list. The request/context, authentication
selection, crypto ports, repositories, claims verification and scheme remain the consumer's responsibility. Bind an
Agent's signed context to the actual incoming method, authority, path, canonical query and body; a valid signed request
for another target is not authentication of this native request. Consumers own canonicalization/proxy handling; the
controlled native fixtures derive those fields from the current request, not a reused MCP request. Never infer authority from an incoming `SecurityContext` attribute. No default credentials or fallback to a different
identity are provided. Operational factory/provider exceptions are not anonymous access. The
[current authority contracts](authority-contracts.md) distinguish known User/session refusal from wrong repository
identities and malformed/incomplete Role or Permission snapshots. Those internal faults must reach the safe server
error owner, not become ordinary authentication rejection. Agent diagnostic classifications retain the same distinction.

`PermissionAdmission::admit($declaration, $authority)` is the reusable inward check for later native adapters. It
returns a `SecurityContext` (or null only for explicit public admission); it adds no business messages/events/commits.
It uses existing `hasPermission` semantics, not a duplicate permission-policy engine. HTTP classification stays outward.
The PSR middleware passes the selected context to downstream code under the `SecurityContext::class` request attribute,
replacing any incoming copy. Downstream use cases still enforce their own record/relationship/tenant scope and protect
alternate Command, Query, worker and direct method entry points. Coarse admission is not a record grant.

## Request and nested lifetimes

Create request-owned authentication providers on **each factory invocation**. The middleware may be reused, but must
not capture a cached provider/authority/security context across requests. Providers intentionally cache a complete
immutable snapshot within one short request. Cooperating downstream code uses that selected context or the same
request-owned provider, not a second signed-request authentication transaction. Checking multiple requirements does
not replay HMAC or consume another nonce. Failed requests do not make their partial context available to the next one.

Every independent native entry, including nested dispatch with the same forwarded object, must establish a
**distinct request identity before routing** and remove copied `SecurityContext`. The executable recipes below
clone the qualified concrete `Slim\Psr7\Request` and strip that attribute at each entry. They preserve request
fields/body bytes for consumer canonicalization; they do not promise arbitrary non-cloneable PSR implementations.
Other implementations need their own qualified entry composition. A fresh Yii Router alone does not establish
this ownership boundary. Never use a client-copyable attribute as the trusted dispatch marker.

Keep the same shared `RequestAuthentication` across adjacent cooperating gates **within** that dispatch, not as
an implicit parent handoff. Each independent entry that needs authentication invokes the factory for a new
provider/context and trusted selection, even if a reused container supplies the same authentication object.
A copied parent SecurityContext is ignored as authentication. This feature supplies **no implicit parent handoff**.
For a same-short-request cooperating operation, use the already admitted snapshot in the original flow;
do not replay the parent's signed request through a fresh Agent provider. An independently authenticated nested Agent
request needs its own valid nonce. Consumers needing a delegated nested identity must separately establish trusted
handoff/provenance and scope; an arbitrary caller attribute is not that contract. Long-lived/background work uses
[current continuation authority](agent-continuation-authorization.md), not an indefinitely retained HTTP snapshot.

## Slim composition

Opt in to `slim/slim:^4.15.3` and a suitable PSR-7/17 implementation (fixtures use `slim/psr7:^1.8`). The authority
factory below is consumer-owned and constructs one of the real providers described above; it is not a package service ID.

```php
$selected = SelectedAction::method(ReportAction::class, 'handle');
$requirements = new PermissionRequirements($selected, $declaration); // null route policy is valid for Attributes.
$selectedAction = static function (ServerRequestInterface $request): SelectedAction {
    $route = RouteContext::fromRequest($request)->getRoute();
    if ($route?->getCallable() !== ReportAction::class.':handle') {
        throw new LogicException('Unexpected selected action.');
    }

    return SelectedAction::method(ReportAction::class, 'handle');
};
$permission = new PermissionMiddleware($requirements, $selectedAction, $authorityFactory);
$route = $app->get('/reports/{id}', ReportAction::class.':handle');
$route->add($protectedValidator);
$route->add($permission); // Slim route middleware is LIFO: admission runs before validation.
$app->addRoutingMiddleware();
$app->add($errors); // Common SafeJSendErrorMiddleware, outermost on this selected ordinary HTTP pipeline.
// This is the entry for every independent dispatch, including nested same-object reentry.
$entry = static function (Request $incoming) use ($app): ResponseInterface {
    $request = (clone $incoming)->withoutAttribute(SecurityContext::class);

    return $app->handle($request);
};
$response = $entry($request);
```

Imports include concrete `Slim\Psr7\Request`, PSR `ResponseInterface`, Application
`Authorization\Service\SecurityContext`, Application `Action\SelectedAction`,
`Authorization\Admission\PermissionRequirements`,
PSR `ServerRequestInterface` and Slim `Routing\RouteContext`. The example deliberately supports one known action;
consumer extraction must match each actual supported native callable, never guess from headers/arguments.
Qualified forms: closure `(request, response)` with its exact closure binding, invokable class-string and
`ClassName:method`, including inherited/overridden methods, with native route arguments present. Routing occurs before route-bound admission. The fixture proves denied requests skip the validator,
action and lazy construction of these selected action forms. It does not promise pre-construction protection for
objects instantiated earlier by consumer bootstrap/container setup, or every Slim invocation strategy/middleware.
Do not install Slim's raw-debug error renderer or the legacy Common JSend middleware on the same selected path.
Unknown Slim HTTP exception types are **not** implicitly classified: with the selected Common policy, an unmatched
Slim route thrown inside the boundary yields generic 500. A consumer wanting native 404/405 translation must compose
an explicit type-based mapper and valid Allow metadata, not inspect exception codes/messages.

## Yii 3 composition

Opt in to `yiisoft/router:^4.0.2`, `yiisoft/router-fastroute:^4.0.3` and
`yiisoft/middleware-dispatcher:^5.4`. The qualified DI/injector fixture versions are `yiisoft/di:^1.4.1` and
`yiisoft/injector:^1.2.2`. These are Yii 3 components, not Yii 2 or a claim about every Yii application package.

```php
$action = [ReportAction::class, 'handle'];
$selected = SelectedAction::method($action[0], $action[1]);
$permission = new PermissionMiddleware(
    new PermissionRequirements($selected, $declaration),
    static fn(ServerRequestInterface $request): SelectedAction => $selected,
    $authorityFactory
);
$route = Route::get('/reports/{id}')
    ->middleware($permission->process(...), $protectedValidator)
    ->action($action);
$collector = new RouteCollector();
$collector->addRoute($route);
$routes = new RouteCollection($collector);
$factory = new MiddlewareFactory($container);
// This is the entry for every independent dispatch, including nested same-object reentry.
$entry = static function (Request $incoming) use (
    $routes, $responseFactory, $factory, $errors, $notFoundHandler
): ResponseInterface {
    $request = (clone $incoming)->withoutAttribute(SecurityContext::class);
    $router = new Router(new UrlMatcher($routes), $responseFactory, $factory, new CurrentRoute());
    $pipeline = new MiddlewareDispatcher($factory);

    return $pipeline->withMiddlewares([$errors->process(...), $router->process(...)])
        ->dispatch($request, $notFoundHandler);
};
$response = $entry($request);
```

The same concrete `Slim\Psr7\Request`, PSR `ResponseInterface` and Application
`Authorization\Service\SecurityContext` imports apply here. Other imports are the actual
`Yiisoft\Router` Route/RouteCollector/RouteCollection/CurrentRoute,
`Yiisoft\Router\FastRoute\UrlMatcher`, `Yiisoft\Router\Middleware\Router` and
`Yiisoft\Middleware\Dispatcher` MiddlewareFactory/MiddlewareDispatcher. Admission uses the PSR-15 **process callable**
that Yii's native definition factory supports, not a fabricated common router or an unsupported bare object definition.
Bind the exact immutable `$action` definition to both the route and its middleware; do not attach this binding to
a different action or resolve policy from copied request attributes. For a closure, use `SelectedAction::closure()`
with that same closure object and an explicit route declaration. The native route middleware list runs admission
before the validator/action. Qualified actions are a closure and `[ClassName::class, 'method']`, including inherited
methods, with native lazy resolution. No universal pre-container-construction promise is made.

`CurrentRoute` has single-assignment URI/route state. Do **not** reuse a Router/CurrentRoute across requests or nested
routing. Reuse immutable route definitions/matcher services only with an actually qualified request-owned context.
The fixtures serve repeated/nested requests in one process with a cloned request stripped of copied context
and a new CurrentRoute/Router each time. Distinct attributed targets with one shared authentication object prove
fresh nested selection, denial/provider-fault cleanup and unchanged parent context. Both-order cooperating Agent
gates still select once and consume one nonce inside an entry. Native unmatched
routing delegates to the supplied 404 handler and never calls a stale protected target. Yii's native method-failure
responses remain native responses. No request arguments or CurrentRoute name are used as authority/declarations.

## One safe error owner, selected ordinary HTTP only

Explicitly compose Common's canonical `Adapter\Middleware\Psr15\SafeJSendErrorMiddleware`,
`Adapter\Http\Error\SafeHttpErrorPolicy`, a required `SafeHttpDiagnostics`, PSR-17 `JSendResponseFactory` and optional
`SafeHttpProjector`. See Common's shipped `docs/safe-http-errors.md`. This package does not add another renderer/logger.

- Inward missing authentication and typed User `PrincipalResolutionException` become Common
  `Application\Error\Exception\AuthenticationRequired`. Agent translation accepts only the actual provider's
  `AUTHENTICATION_REJECTED` and `AGENT_AUTHORITY_NOT_CURRENT` diagnostic classifications. No message/code/cause inference.
- Agent `PERMISSION_SNAPSHOT_INVALID` and opaque `RESOLUTION_FAILED` remain operational failures (generic 500), as do
  unknown provider/storage/resolver/configuration faults. Missing Permission after complete resolution becomes Common
  `PermissionDenied` (403). Domain exceptions remain Domain-owned and inherit no Application/HTTP type.
- Supply a consumer-selected, validated challenge through `SafeHttpPresentation(metadata: new SafeHttpMetadata(...))`
  for `AuthenticationRequired`. There is no universal Bearer scheme. Missing/invalid 401 challenge, invalid/misapplied
  required headers, projection, sink, serialization or primary renderer failure produce generic safe 500. Common
  forwards only its allowlisted validated metadata. A projector changes public presentation, never type classification.
- Default bodies are fixed safe JSend text, never exception messages/codes/causes, raw validation errors or credentials.
  Deliberate public data must be plain finite JSON, bounded by Common's depth/encoding checks; public-safe semantics
  remain the projector author's responsibility. Invalid objects/nonfinite data are tested through actual fallback.
- Exactly one reporting **attempt** precedes projection/rendering. The consumer's redaction-aware diagnostics owner
  must redact before any logger/formatter sees messages, traces, causes or context; never forward a Throwable as PSR-3
  `exception` context. A failed sink is not delivered evidence; there is no recursive/duplicate report. If both primary
  and fallback PSR factories fail, Common throws only sanitized `Unable to render safe HTTP error.` without a cause.

The PSR boundary is selected explicitly and emits JSON on its selected path regardless of Accept; there is no HTML
negotiation or client debug override. Tests exercise HTML Accept on a selected JSON path as well. This is **not**
universal HTML sanitization. Failures outside the selected boundary, earlier middleware/loggers, event listeners,
other response formatters and fallback outer host handling require consumer qualification.

Keep MCP and OAuth routes outside ordinary HTTP/JSend failure wrapping. MCP retains JSON-RPC errors, unknown/unavailable
Tool concealment and exact-class Tool failure mappings to authored constants. A subclass is not automatically mapped
by inheritance. OAuth keeps its empty protocol rejections/challenges and scope policy; scopes do not grant AccessControl
Permissions. Fixtures route actual Common MCP invocation and OAuth response construction independently and prove no
ordinary authority/validator/report collaborator is invoked on excluded protocol routes. Existing Resource/Skill/Tool
behavior stays unchanged. Agent OS's custom mapper is not a drop-in migration target; no consumer bootstrap is modified.

## Qualified minimums and limits

Current package minimum is Common `^1.5.1`; the earlier safe-error qualification used `^1.5.0`. TASK-00146 requalifies
ordinary typed categories, privacy, fallback and optional native lanes; see [current contracts](ordinary-http-failures.md).
The full default suite rechecks shared/Slim/Yii behavior on that current installed dependency. The table and source/archive counts below preserve TASK-00103's original qualification lane,
not the current installed lock or a claim that Laravel works with 1.4.0.

At that checkpoint the installed lane was also the **selected minimum** lane: every listed lower bound below was an
actual installed tag, not a guessed oldest compatible release. Common was narrowed from `^1.3` to `^1.4` because 1.3 does not guarantee safe
HTTP declarations. No compatibility aliases/readers or development branches are used. Native libraries are dev fixture
dependencies plus Composer suggestions, not mandatory production frameworks. PSR HTTP contracts are explicit runtime
requirements. A separate no-dev install proves inward User/Agent admission and adapter autoload without Slim/Yii.
Additional exact-minimum Slim-only and Yii-only consumer fixtures exercise actual native 204/403 responses, real User
admission and validator/report counts with the other framework absent. Their separate locks/traces are retained;
Yii's fixture uses Slim's standalone PSR-7 implementation, not the Slim application/router package.

| Component minimum | Tagged source commit |
| --- | --- |
| Common v1.4.0 (`^1.4`) | `87b9580c072f3149d574e09865daa79620c92118` |
| Slim 4.15.3 (`^4.15.3`) | `3675bf6baac66b07032575b7bef4200b60b7974b` |
| Slim PSR-7 1.8.0 (`^1.8`) | `76e7e3b1cdfd583e9035c4c966c08e01e45ce959` |
| Yii Router 4.0.2 (`^4.0.2`) | `dff8d252e87eb99e415fbc59439546d3ea432fa9` |
| Yii FastRoute 4.0.3 (`^4.0.3`) | `13f97b5c7934aacaece3318ab947a12fcbe532f3` |
| Yii Middleware Dispatcher 5.4.0 (`^5.4`) | `26b7811d442c2d3bf5ab4d1c8f577750a6c0ed30` |
| Yii DI 1.4.1 (`^1.4.1`) | `09545283f29b6311805eca870bf01d2603704c63` |
| Yii Injector 1.2.2 (`^1.2.2`) | `a3627145dc1d214d78d061dbef4f90773aadd747` |

Tag refs are verified against upstream and all installed `src/` or `Slim/` blobs plus Composer manifests match downloaded
tag archives (803 files total). Exact locks, archive/source manifests and runtime identities are in the TASK Work
receipt. Package PHP requirement remains 8.5+; the qualified native runtime is patched PHP **8.5.12**, Xdebug 3.5.3.
The package's wrapper security floor is 8.5.12; the Composer platform emulation 8.5.4 is resolver metadata, not an
executed minimum-runtime result or a claim that older insecure patches were tested. Listed Yii components declare
PHP through 8.5; future PHP lines require separate qualification. Semver ranges express support, not proof of every
future release. The current selected minimums pass the actual native journeys, not only class-existence checks.

Production code is included in normal mirrored Adapter/Application tests, PHPCS, PHPStan, Rector, Deptrac and exact
statement coverage. The expanded default native/OpenAPI workload has an explicit 512 MiB PHPUnit memory budget;
the first full attempt exhausted PHP's default 128 MiB during OpenAPI generation, without a completed coverage result.
No suite, assertion, fail-on-skipped or exact-coverage threshold is relaxed. These controlled native fixtures do **not** qualify a consumer bootstrap, persistence/cryptography,
all-writer races, actual policy/scope, diagnostics-sink redaction, proxies, deployed runtimes or security. Independent
technical Review and behavioral QA remain required; package evidence is neither adoption nor release authorization.
