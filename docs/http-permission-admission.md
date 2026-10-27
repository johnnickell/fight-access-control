# Optional HTTP Permission admission

TASK-00103 supplies route-bound PSR-15 admission, qualified separately with **Slim 4** and **Yii 3** components.
It does not register routes, authentication, grants, containers, error handlers or another framework. Native
Symfony/Laravel/CodeIgniter integrations remain separate work. The combined EPIC release is not yet complete.

## Inward contract and trusted composition

`Application\AccessControl\Authorization\PermissionDeclaration` has exactly three `AdmissionMode` values:

| Mode | Requirements | Behavior |
| --- | --- | --- |
| `PUBLIC` | Empty | No authority factory/provider resolution; remove inherited SecurityContext attribute. |
| `AUTHENTICATED` | Empty | Require one complete actual User or Agent authority, without a Permission grant. |
| `PROTECTED` | Nonempty list of distinct `PermissionName` values | Require every named Permission. |

The constructor rejects wrong types, associative arrays, duplicate names, empty protected requirements and
requirements on other modes. Permission names retain their existing canonical validation. There is no attribute
scanner, callback reflection policy, route-name allowlist, union/override rule or implicit public default.
A missing declaration (`null`) fails as configuration error, before resolving authentication. Invalid declarations
normally fail at composition/bootstrap; dynamic composition faults also remain server failures.

Bind a `Adapter\Middleware\Psr15\PermissionMiddleware` instance containing that declaration **directly to each
selected native route**, before validation and action invocation. The native router selects the route; the bound
instance is its exact association, independent of callback identity. Two routes sharing a callback may have different
requirements. Headers, route arguments, request attributes and copied principal/Permission arrays cannot select or
replace the declaration. Every ordinary route must deliberately install its admission instance, including explicit
public routes. A route with no middleware is outside this adapter's coverage, **not** automatically protected.
Audit actual consumer registration; this package cannot detect an omitted registration in another application.

The required authority factory has signature `Closure(ServerRequestInterface): ?RequestAuthority`. It is **trusted
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
identity are provided. Operational factory/provider exceptions are not anonymous access.

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

Nested dispatch must enter its own explicitly composed route admission with a new provider/context and trusted
selection. A copied parent SecurityContext is ignored as authentication. This feature supplies **no implicit parent
handoff**. For a same-short-request cooperating operation, use the already admitted snapshot in the original flow;
do not replay the parent's signed request through a fresh Agent provider. An independently authenticated nested Agent
request needs its own valid nonce. Consumers needing a delegated nested identity must separately establish trusted
handoff/provenance and scope; an arbitrary caller attribute is not that contract. Long-lived/background work uses
[current continuation authority](agent-continuation-authorization.md), not an indefinitely retained HTTP snapshot.

## Slim composition

Opt in to `slim/slim:^4.15.3` and a suitable PSR-7/17 implementation (fixtures use `slim/psr7:^1.8`). The authority
factory below is consumer-owned and constructs one of the real providers described above; it is not a package service ID.

```php
$permission = new PermissionMiddleware($declaration, $authorityFactory);
$route = $app->get('/reports/{id}', ReportAction::class.':handle');
$route->add($protectedValidator);
$route->add($permission); // Slim route middleware is LIFO: admission runs before validation.
$app->addRoutingMiddleware();
$app->add($errors); // Common SafeJSendErrorMiddleware, outermost on this selected ordinary HTTP pipeline.
$response = $app->handle($request);
```

Qualified forms: closure `(request, response)`, invokable class-string and `ClassName:method`, with native route
arguments present. Routing occurs before route-bound admission. The fixture proves denied requests skip the validator,
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
$route = Route::get('/reports/{id}')
    ->middleware($permission->process(...), $protectedValidator)
    ->action([ReportAction::class, 'handle']);
$collector = new RouteCollector();
$collector->addRoute($route);
$routes = new RouteCollection($collector);
$factory = new MiddlewareFactory($container);
// Per incoming request, including nested dispatch:
$router = new Router(new UrlMatcher($routes), $responseFactory, $factory, new CurrentRoute());
$pipeline = new MiddlewareDispatcher($factory);
$response = $pipeline->withMiddlewares([$errors->process(...), $router->process(...)])
    ->dispatch($request, $notFoundHandler);
```

Imports are the actual `Yiisoft\Router` Route/RouteCollector/RouteCollection/CurrentRoute,
`Yiisoft\Router\FastRoute\UrlMatcher`, `Yiisoft\Router\Middleware\Router` and
`Yiisoft\Middleware\Dispatcher` MiddlewareFactory/MiddlewareDispatcher. Admission uses the PSR-15 **process callable**
that Yii's native definition factory supports, not a fabricated common router or an unsupported bare object definition.
The native route middleware list runs admission before the validator/action. Qualified actions are a closure and
`[ClassName::class, 'method']`, with native lazy resolution. No universal pre-container-construction promise is made.

`CurrentRoute` has single-assignment URI/route state. Do **not** reuse a Router/CurrentRoute across requests or nested
routing. Reuse immutable route definitions/matcher services only with an actually qualified request-owned context.
The fixtures serve repeated requests in one process with a new CurrentRoute/Router each time. Native unmatched
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

The installed lane is also the **selected minimum** lane: every listed lower bound below is the actual installed tag,
not a guessed oldest compatible release. Common is narrowed from `^1.3` to `^1.4` because 1.3 does not guarantee safe
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
