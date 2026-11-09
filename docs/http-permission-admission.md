# Optional HTTP Permission admission

TASK-00103 supplies route-bound PSR-15 admission, qualified separately with **Slim 4** and **Yii 3** components.
It does not register routes, authentication, grants, containers, error handlers or another framework.
[TASK-00104's Symfony guide](symfony-permission-admission.md) covers the separate optional native post-routing
subscriber and Common exception composition. [TASK-00106's CI4 guide](codeigniter-permission-admission.md) covers native
before-filter admission and its thin Common-backed selected JSON error bridge. The
[Laravel guide](laravel-permission-admission.md) covers its present native middleware. Current typed categories,
validation privacy and all seven native contracts are reconciled in [ordinary HTTP failures](ordinary-http-failures.md).
The combined EPIC release is not yet complete.

## Portable admission evidence assessment (Unreleased)

[TASK-00180](../planning/tasks/00180-TASK.md) assesses the current source against
[EPIC-00018](../planning/epics/00018-EPIC.md) and [TICKET-00052](../planning/tickets/00052-TICKET.md).
This is coverage accounting, not implementation, new supplier acceptance or consumer qualification.
“Accepted” below identifies bounded historical supplier acceptance; current source/tests establish what remains present.
TASK-00103–00106 have independent Review/QA evidence, with later ownership/authority/HTTP changes integrated.
In particular, Laravel and CI4 are implemented, unlike the EPIC's older inspected-baseline paragraph.
TASK-00146 supplies the current safe-error/minimum reconciliation; older native tag tables remain historical.

### Capability selection and actual native evidence

**Permission-only currently means explicit trusted `PermissionDeclaration`, not portable `RequiresPermission`.**
All four adapter implementations call shared inward admission without Feature evaluation/discovery/repository dependencies.
FeatureAvailability can be called independently, but is not an HTTP Feature gate. There is no production Feature
middleware/subscriber/filter. TASK-00170 supplies effective Feature metadata; TASK-00171 now supplies
[portable Permission metadata](permission-requirements.md), without native activation. TASK-00172–00179 retain
native admission/integration ownership; metadata proof is not native acceptance.

| Native family | Current Permission-only evidence to reuse | Feature-only / combined disposition and actual owner |
| --- | --- | --- |
| Slim 4 | TASK-00103: [PSR middleware](../src/Adapter/Middleware/Psr15/PermissionMiddleware.php), [native tests](../tests/Adapter/Middleware/Psr15/PermissionMiddlewareTest.php) and [NativePipeline](../tests/Adapter/Middleware/Psr15/NativePipeline.php), separately selecting `slim`. Route-bound LIFO admission precedes validation/lazy action construction for qualified forms. | Native Feature-only pending TASK-00172; portable Permission pending TASK-00176. Combined portable admission unqualified until those actual outputs are reconciled by TASK-00181. |
| Yii 3 | Same shared source/tests, separately selecting `yii`: actual Yii router/dispatcher and process callable, not inferred from Slim. New Router/CurrentRoute per request; closure and class/method forms qualified, not universal invokable support. | Native Feature-only pending TASK-00172; portable Permission pending TASK-00176. Combined portable admission unqualified; retain Yii-specific routing lifetime. |
| Symfony | TASK-00104: [subscriber](../src/Adapter/Http/Symfony/EventSubscriber/PermissionSubscriber.php), [native tests](../tests/Adapter/Http/Symfony/EventSubscriber/PermissionSubscriberTest.php), [guide](symfony-permission-admission.md). Trusted rematch at kernel.request priority 16, after routing 32 and before controller resolution; independent validation/IsGranted still apply. | Feature-only pending TASK-00173; portable Permission pending TASK-00177. Combined portable admission unqualified. kernel.controller alone cannot preserve the existing early construction guarantee. |
| Laravel | TASK-00105: [middleware](../src/Adapter/Middleware/Laravel/PermissionMiddleware.php), [native tests](../tests/Adapter/Middleware/Laravel/PermissionMiddlewareTest.php), [guide](laravel-permission-admission.md). Exact serving Route/action bindings, admission before bindings/FormRequest/action. Legacy controller middleware discovery can construct before admission. | Feature-only pending TASK-00174; portable Permission pending TASK-00178. Combined portable admission unqualified. Reuse present native groundwork rather than implement TASK-00105 again. |
| CodeIgniter 4 | TASK-00106: [filter](../src/Adapter/Http/CodeIgniter/PermissionFilter.php), [native tests](../tests/Adapter/Http/CodeIgniter/PermissionFilterTest.php), [guide](codeigniter-permission-admission.md). Current matched options, zero-argument delegating shell, before-filter Response short-circuit, request-keyed contexts and explicit safe loop. | Feature-only pending TASK-00175; portable Permission pending TASK-00179. Combined portable admission unqualified. Reuse the existing generic error bridge without activating Permission; process-global reentrant nested loops remain unsupported. |

The shared/native tests above execute actual providers with controlled persistence/crypto and assert denied work,
status/headers, context and nonce effects. They are not merely injection counts. Permission-only base operation is
already demonstrated without a Feature gate; this does not qualify the future portable selection path. No current
native Feature-only or combined success is claimed for any of the five families.

### Requirement-to-evidence checklist

Paths below identify maintained executable evidence, not commands to run a second qualification programme.
Application test paths are under `tests/Application/AccessControl/`; production owners mirror `src/Application/AccessControl/`.

| Accepted obligation | Existing evidence considered and current disposition |
| --- | --- |
| Canonical declarations, cumulative class/method/ancestor/override/route requirements, validation before deduplication and exact action association | [FeatureFlag](../src/Application/AccessControl/Feature/Attribute/FeatureFlag.php) and [FeatureFlagTest](../tests/Application/AccessControl/Feature/Attribute/FeatureFlagTest.php) now support repeated class/method declaration-only metadata. TASK-00170 adds [effective Feature requirements](feature-references.md) and shared pure SelectedAction ancestry/association, with native construction and hierarchy tests; independent acceptance remains separate from implementation. TASK-00170 owns this Feature declaration path; TASK-00171 owns portable Permission composition. Closure/invokable/inherited association and ambiguous-target rejection must consume those outputs, not a copied adapter merger. |
| Scoped complete discovery, preparation/provisioning/retirement and no metadata side effects | [FeatureReferenceDiscovery](../src/Application/AccessControl/Feature/Discovery/FeatureReferenceDiscovery.php), [FeatureDiscoveryResultTest](../tests/Application/AccessControl/Feature/Discovery/FeatureDiscoveryResultTest.php), [ProvisionFeaturesHandlerTest](../tests/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesHandlerTest.php), [ValidateFeaturePreparationHandlerTest](../tests/Application/AccessControl/Feature/QueryHandler/ValidateFeaturePreparationHandlerTest.php), [RemoveFeatureHandlerTest](../tests/Application/AccessControl/Feature/CommandHandler/RemoveFeatureHandlerTest.php). Reuse complete-empty versus unavailable/partial/invalid, CANDIDATE versus CURRENT and real reference/revision guards. TASK-00170 adds class/repeated/overridden-parent discovery alignment and actual composed-handler tests; no production scanner is supplied. |
| Raw Permission validation; deliberate PUBLIC/AUTHENTICATED/PROTECTED; effective conflict rejection | [PermissionDeclaration](../src/Application/AccessControl/Authorization/Admission/PermissionDeclaration.php) and [PermissionAdmissionTest](../tests/Application/AccessControl/Authorization/Admission/PermissionAdmissionTest.php) reject associative/non-list/wrong-type/duplicate/empty protected input and requirements on other modes. Missing declaration faults; absent Attribute is not PUBLIC. Reuse these tests unchanged in meaning. TASK-00171's [Permission requirements](permission-requirements.md) add effective deduplication and PUBLIC/AUTHENTICATED-only conflict rejection with native Attribute/hierarchy tests, preserving raw validation. Native consumption remains separate. |
| Feature restriction versus action authorization; OFF/PREVIEW/ON, exact binding identity, anonymous/User/Agent and no writes | [FeatureAvailability](../src/Application/AccessControl/Feature/Service/FeatureAvailability.php) and [FeatureAvailabilityTest](../tests/Application/AccessControl/Feature/Service/FeatureAvailabilityTest.php) establish OFF denies everyone, PREVIEW uses captured bound Permission ID (not name/role), ON allows anonymous Feature passage, and broken/mismatched bindings fault at every status. [PermissionAdmissionTest](../tests/Application/AccessControl/Authorization/Admission/PermissionAdmissionTest.php) separately proves all Permissions, User Role-derived/Agent direct authority and no administrator bypass. Neither independent result proves a combined native gate cannot bypass the other. |
| Fresh settings but captured grants; no live/background lease | [FeatureAvailabilityConformance](../tests/Application/AccessControl/Feature/Conformance/FeatureAvailabilityConformance.php) checks status/binding changes with the same evaluator/principal and explicit later rechecks; [FeatureManagementTest](../tests/Application/AccessControl/Feature/CommandHandler/FeatureManagementTest.php) checks real setting/binding edits. Reuse this shared proof, not five copies. Actual ORM/cache freshness and all-writer storage behavior remain consumer-unqualified; refreshing settings does not refresh principal grants. |
| Trusted current authority, no invalid-context-to-anonymous fallback, one authentication/nonce and isolated request lifetime | [RequestAuthority](../src/Application/AccessControl/Authorization/Admission/RequestAuthority.php), PermissionAdmissionTest and each native test above prove real User/Agent resolution, copied-context rejection, ordinary multiple-Permission single nonce, fresh sequential requests and supported nested isolation. Current PUBLIC admission deliberately clears context and skips authentication. These are sufficient base Permission facts, **not** a trusted cross-capability context-sharing contract. Feature context adaptation/cooperation is pending TASK-00172 and native peers; portable peers must preserve it without trusting incoming attributes. |
| Generic Feature 404 versus missing/broken definitions, configuration/provider/storage faults and Permission refusal/challenge | FeatureAvailabilityTest already distinguishes false from missing/broken/mismatched/state/storage exceptions. Native Permission tests establish known 401/403 versus opaque 500 and validated consumer challenges. Reuse [ordinary HTTP failures](ordinary-http-failures.md) and its actual current categories; HTTP translation of ordinary Feature denial only is pending TASK-00172–00175. Do not reuse optional earlier broken-binding concealment advice as the new Feature contract. |
| Native order, construction, target/response lifetime, exceptions and unsupported forms | The five-family references above establish existing Permission boundaries, not universal pre-construction protection. Symfony tests cover distinct main/subrequests and RequestStack restoration; Laravel covers reused/nested requests but not automatic ambient router/container restoration; CI4 covers reset sequential requests and distinct context keys, not reentrant global-service dispatch. New metadata/Feature insertion must preserve these limits in its owning native slice. Admission/merging creates no business Command/Query/Event, transaction or mutation audit; normal authentication effects remain separate. |
| One safe reporting owner, header/negotiation/finite output/fallback, protocol exclusions | PSR native tests plus [ProtocolExclusionTest](../tests/Adapter/Middleware/Psr15/ProtocolExclusionTest.php), Symfony native tests, Laravel [SafeLifecycleTest](../tests/Adapter/Middleware/Laravel/SafeLifecycleTest.php), CI4 native tests and [SafeErrorResponderTest](../tests/Adapter/Http/CodeIgniter/SafeErrorResponderTest.php) cover sink/projector/encoder/renderer faults, one report attempt and safe finite output. Current TASK-00146 reconciles all paths. Reuse this evidence; only new Feature translation/insertion needs its supplier proof. Selected JSON is not global HTML safety; MCP/OAuth envelopes stay separate. Symfony needs `handleAllThrowables: true`; outer/later response handling is not automatically contained. |
| Independent installation, optional dependencies, tagged sufficient minimum/ordinary lanes and current guidance | [composer.json](../composer.json) requires Common `^1.4.2` and PSR contracts, with native frameworks dev-only/suggested and no automatic registration. [Current minima and consumer bounds](ordinary-http-failures.md#consumer-obligations-and-evidence-bounds) supersede historical tag floors. TASK-00103–00106 supplied isolated/core/native lanes; TASK-00146 requalified current Common/native minima and optional no-dev lanes. Reuse their exact retained locks/tag/source/exit/acceptance evidence, not an installed path or coverage percentage. Those lanes do not yet qualify unimplemented Feature/portable selections or every version in a semver range. |

### Genuine gaps and smallest recovery inputs

These are bounded unproved contracts, not demonstrated vulnerabilities or a per-framework test backlog. Pending
suppliers retain their own complete tests/docs/minimum lanes/full gate/independent acceptance. This early map can
finish before them; it creates no new blocker or edge. [TASK-00181](../planning/tasks/00181-TASK.md) owns final
candidate-compatible reconciliation, not supplier implementation or deferred acceptance.

| Gap / risk | Why existing proof is insufficient | Owner and minimal recovery input |
| --- | --- | --- |
| Effective metadata could omit an ancestor/override or deduplicate invalid raw input into valid protection. | TASK-00170's new Feature hierarchy/discovery tests cover that metadata risk without native extraction or portable Permission proof; explicit route Permission tests do not traverse portable Attributes. | TASK-00170/00171: accepted actual traversal/declaration/discovery output with shared unit/native Attribute proof and preserved raw validation. TASK-00176–00179 connect it to actual native targets. Consume that proof once; no extra test per hierarchy/framework cell. |
| Feature-only lacks native enforcement and denial/fault translation. | Evaluator Boolean/exception tests cannot prove HTTP short-circuit, trusted selected context or generic Feature 404. Existing safe error tests do not insert a Feature gate. | TASK-00172–00175: complete independently accepted native supplier paths, including Feature-only installation. Reuse shared evaluator/status/freshness and existing error evidence; native proof is needed only for actual insertion/order/lifetime/translation. |
| Combined gates could bypass each other, erase trusted Feature context on PUBLIC Permission admission, or authenticate an Agent twice. | Current adapters clear incoming context (PSR replaces its immutable attribute downstream; Symfony/Laravel/CI4 clear before admission), deliberately preventing forgery. Permission tests prove one nonce within one gate, not across two independent gates. There is no current second gate to test. | TASK-00172 owns shared trusted cooperation; native Feature and portable Permission suppliers preserve it. TASK-00181 first consumes their actual accepted evidence, checking PUBLIC+Feature, ON+denied Permission and either-gate refusal against that implementation. Only a residual shared logic gap warrants a unit; add a native case only if actual ordering/request mutation/lifetime cannot be established otherwise. Never preserve arbitrary incoming context or replay authentication as a workaround. |
| Future integration may outgrow base native/lane evidence. | Base lifecycle/error/minimum passes cover their original/current explicit Permission paths, not new metadata extraction or combined selection. A terminal TASK or historical receipt alone cannot establish compatibility with the final candidate. | TASK-00181: exact accepted supplier-to-candidate source/test/dependency mapping plus their complete logs/exits/receipts; reconcile affected examples/Unreleased/selection guidance. Request only the missing affected input from its supplier; no blanket rerun of native suites, minimum lanes or diagnostics. |

### Consumer limits retained

No actual consumer authentication/crypto/nonce store, record/tenant policy, scanner/code identity/completeness,
production persistence/cache/locks/all-writer fences, bootstrapping/route coverage, sink redaction, proxy
canonicalization, installed runtime, outer/non-JSON handling or concurrency is qualified here. Consumers still
explicitly register selected capabilities and safe boundaries, retain alternate-entry/continuation checks and
qualify their own real composition. Unsupported forms remain outside the documented native contracts; missing
registration is not automatic protection. No Attribute replacement in Agent OS, Common/consumer repair, schema/data
operation, compatibility mode, release, deployment or new grant is authorized by this assessment.

## Inward contract and trusted composition

`Application\AccessControl\Authorization\Admission\PermissionDeclaration` has exactly three `AdmissionMode` values:

| Mode | Requirements | Behavior |
| --- | --- | --- |
| `PUBLIC` | Empty | No authority factory/provider resolution; remove inherited SecurityContext attribute. |
| `AUTHENTICATED` | Empty | Require one complete actual User or Agent authority, without a Permission grant. |
| `PROTECTED` | Nonempty list of distinct `PermissionName` values | Require every named Permission. |

The constructor rejects wrong types, associative arrays, duplicate names, empty protected requirements and
requirements on other modes. Permission names retain their existing canonical validation. Pure optional
[PermissionRequirements](permission-requirements.md) composes portable Attributes using the shared selected-action
traversal. Existing native adapters do not consume it automatically. There is no production scanner, route-name
allowlist, union/override rule or implicit public default.
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

Current package minimum is Common `^1.4.2` for the selected native safe-error composition. TASK-00146 requalifies
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
