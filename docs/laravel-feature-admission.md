# Native Laravel Feature admission (Unreleased)

`Adapter\Middleware\Laravel\FeatureMiddleware` restricts the exact routed action before protected binding,
validation and action work. It works without Permission middleware. It consumes the shared
[effective declarations and admission contract](http-feature-admission.md), not a second Attribute merger,
authenticator or evaluator. Feature ON is not authorization for the action. The
[current reconciliation](http-permission-admission.md#current-portable-admission-reconciliation-unreleased) connects
Feature-only, portable Permission-only and both-gate evidence without another native testing programme.

## Explicit consumer composition

Select Common `^1.5.1`, Laravel `^13.40.0`, HttpFoundation/HttpKernel `^8.1.11` and Event Dispatcher `^8.1.5`.
Laravel remains optional, not a core production dependency. The tagged minimum and ordinary dependency lanes
exercise the actual kernel/router; a separate core-only lane loads the optional adapter and executes inward
OFF/ON admission with Laravel and HttpKernel absent. These are sufficient tested bounds, not a promise about
all versions, every dependency combination or production consumer installations.

Register/load the actual serving routes first. Then bind their exact `Illuminate\Routing\Route` objects and
unchanged normalized `uses` actions. Explicitly install middleware before `SubstituteBindings`, protected
validators, FormRequests and action dependencies. Route bindings alone do not attach middleware.

```php
use Fight\AccessControl\Adapter\Middleware\Laravel\FeatureMiddleware;
use Fight\AccessControl\Adapter\Middleware\Laravel\RequestAuthentication;
use Fight\AccessControl\Adapter\Middleware\Laravel\SelectedRouteAction;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Illuminate\Routing\Middleware\SubstituteBindings;

$route = $router->get('/preview', $controller)
    ->middleware([FeatureMiddleware::class, SubstituteBindings::class]);
$authentication = new RequestAuthentication($authorityForRequest);
$requirements = new FeatureRequirements(
    new SelectedRouteAction()->action($route->getAction('uses')),
    'consumer.rollout'
);
$app->instance(FeatureMiddleware::class, new FeatureMiddleware(
    $router,
    [[$route, $requirements]],
    $authentication,
    new FeatureAdmission(new FeatureAvailability($featureRepository, $permissionRepository))
));
$kernel->prependToMiddlewarePriority(FeatureMiddleware::class);
```

`$authorityForRequest` is the trusted factory described in the
[Laravel Permission guide](laravel-permission-admission.md#trusted-authority-and-request-lifetime): fresh actual
User or Agent providers, or deliberate anonymous null. It must bind signed Agent input to this actual request;
never select policy or authority from copied context, arbitrary headers, arguments, guard fallback or role names.
Provider/configuration/storage failures throw. They must not become anonymous access. Known authentication
refusals retain existing challenge behavior; malformed provider output is a fault.

A binding `[Route, null]` derives effective Attributes from the selected action. No Attributes means no Feature
restriction and no authority selection, **not** PUBLIC Permission policy. A supplied `FeatureRequirements` adds
trusted route names conjunctively to class/method/repeated/interface/inherited/overridden declarations. Its selected
action must match. Unknown/unbound routes, substituted route objects, changed actions, duplicate bindings,
unsupported actions and malformed requirements fail closed. Request attributes never replace these bindings.
Rebuild bindings after loading cached routes; WeakMaps are not serialized route definitions. Compiled route-cache,
hot replacement, resource-route generation and custom controller dispatchers need separate qualification.

## Maintained runtime flow

This text diagram follows the implementation links below; arrows are calls, not a transaction or authority lease.

```text
Laravel Kernel -> Router: select exact Route and normalized uses action
  -> FeatureMiddleware -> SelectedRouteAction -> FeatureRequirements
  -> shared RequestAuthentication -> actual User/Agent provider (or deliberate null)
  -> Application FeatureAdmission -> FeatureAvailability -> current Feature/Permission reads
     OFF / unmet PREVIEW -> ResourceNotFound -> Common SafeLaravelExceptionHandler -> generic 404
     metadata/provider/storage/binding fault -> same Common handler -> safe error
     all restrictions pass -> bindings -> validators/FormRequest -> action/business work
  -> RequestHandled / response processing (consumer-owned final observers)
finally: RequestAuthentication retires active-chain provider selection
```

Owners: [Feature middleware](../src/Adapter/Middleware/Laravel/FeatureMiddleware.php),
[selected native action](../src/Adapter/Middleware/Laravel/SelectedRouteAction.php),
[request authentication](../src/Adapter/Middleware/Laravel/RequestAuthentication.php),
[effective requirements](../src/Application/AccessControl/Feature/FeatureRequirements.php),
[inward admission](../src/Application/AccessControl/Feature/Admission/FeatureAdmission.php) and
[current evaluator](../src/Application/AccessControl/Feature/Service/FeatureAvailability.php).
No checking Command/Query, provisioning scan, repair, transaction, business mutation, audit or Event is added.
Normal authentication's session/nonce effects remain independent.

## Status, context and cooperating gates

OFF denies everyone. PREVIEW requires the exact stored bound Permission ID in the actual User/Agent snapshot.
ON passes even anonymously but grants no action Permission or target scope. Each check reads current Feature
settings and validates Feature name and Permission identity/binding at every status. Missing/broken definitions,
wrong identities and repository failures are faults, not ordinary 404. A captured principal's grants do not refresh
because Feature settings were reread; a short-request snapshot grants no live/background authority.

When both capabilities apply, pass the **same** `RequestAuthentication` instance to FeatureMiddleware and the
third argument of PermissionMiddleware. The latter also accepts a trusted factory for independent Permission-only
composition. Both restrictions must pass in either order; a PUBLIC Permission gate or empty Feature declaration
must not erase an already trusted context. Provider selection is private active-chain state, not request attributes.
Actual providers memoize authentication so cooperating gates consume one Agent nonce. Do not reuse a provider
singleton across requests or replay a consumed nonce, including after denied admission.

Sequential reuse of kernels, containers and request objects clears copied context and retires active state in
`finally`, including downstream exceptions. Distinct nested requests select their own authority. Parent request
snapshots are not copied as child credentials. Laravel's ambient container/router/facade state still needs consumer
stack/sandbox restoration after nested dispatch. Same-object recursive dispatch, coroutine concurrency and arbitrary
middleware re-entry are not supported authority lifetimes.

## Supported forms and construction limits

Real native tests exercise closures, `Class@method`, `[Class, method]`, invokable classes, inherited/overridden methods,
static `HasMiddleware` and legacy `Controller` discovery. Laravel normalizes class/array/invokable registrations to
`Class@method`; the adapter reads that trusted class metadata without constructing a controller. Use actual concrete
class registrations with matching container resolution, not service aliases or substitutions for different code.
For other application handlers, explicitly route a supported closure and bind its requirements; arbitrary callable,
container-alias and magic-method inference is rejected rather than treated as unprotected.

- Ordinary lazy class forms: denial precedes construction, binding, validators, FormRequest authorization/rules,
  method providers, action and bus work.
- Static `HasMiddleware`: static discovery runs before admission; denial prevents instance construction/action.
- Legacy `Controller`: Laravel can construct the controller while discovering middleware **before admission**.
  Its constructor is not protected, even when the gate is also attached to the route.
- Earlier bootstrap/listeners/global middleware, static metadata and eager container resolutions are not protected.
  Keep these and constructors free of protected effects. Discovery is not authorization.

## Common owns safe errors

Explicitly select Common's actual `SafeLaravelExceptionHandler` as documented in the
[Laravel safe composition](laravel-permission-admission.md#one-common-owned-safe-reporting-and-rendering-boundary).
Bind its shared policy, one redaction-aware diagnostic sink and optional public-safe projector before resolving it.
There is no Feature handler, reporter, logger, global takeover or MCP/OAuth envelope replacement.

Only ordinary OFF/unmet PREVIEW translates to generic `ResourceNotFound`/404, without Feature or configuration data.
Provider/evaluation/metadata faults are wrapped as internal errors, never classified by arbitrary text/code/cause or
projector guesses. Native qualification retains report-before-render interception before raw callbacks/context/
formatters, including normally ignored failures; one redacted report attempt; validated challenge/Allow/Retry-After;
finite output and sink/projector/primary/fallback encoding faults. Failed reporting is not successful delivery.

Actual JSON-preferred and XHR/wildcard negotiation select safe JSON; explicit HTML, HTML-preferred and missing Accept
may delegate to Laravel. **Delegated non-JSON rendering is not a sanitizer.** Choose JSON-only endpoints or qualify
consumer HTML rendering separately. Arbitrary final observers, response modifications, send/shutdown faults, proxies,
production diagnostics/storage/crypto/authentication and deployed workers remain consumer-owned and unqualified by
package fixtures. Native tests reuse the existing [framework-isolated launcher](laravel-permission-admission.md#tagged-minimum-evidence-and-consumer-qualification).
