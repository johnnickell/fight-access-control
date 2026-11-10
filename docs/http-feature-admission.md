# Optional PSR-15 Feature admission (Unreleased)

`FeatureMiddleware` is an explicitly registered Feature restriction, separately exercised through real Slim 4 and
Yii 3 routing. It does not activate Permission middleware, scan controllers, provision bindings or authorize an action.
The shared `Application\AccessControl\Feature\Admission\FeatureAdmission` consumes validated effective references
and a trusted `SecurityContext` or deliberate anonymous null. It accepts only actual User/Agent snapshots; another
`AuthenticatedAuthority` kind is a configuration fault, not anonymous access.

The optional [native Symfony Feature subscriber](symfony-feature-admission.md) consumes these same inward semantics
without requiring PSR middleware or PermissionSubscriber activation; its native lifecycle and limits are distinct.

## Ownership and runtime flow

The [maintained runtime image and arrow traces](http-feature-admission/README.md) provide a bounded rendered view
alongside this sequence, with editable source, exact implementation revision and explicit omissions.

```mermaid
sequenceDiagram
    participant Router as Native selected route
    participant Gate as PSR FeatureMiddleware
    participant Scope as RequestAuthentication
    participant Admission as Application FeatureAdmission
    participant Availability as FeatureAvailability
    participant Work as Validator / action
    Router->>Gate: Route-bound requirements + trusted selected action
    Gate->>Gate: FeatureRequirements verifies action identity
    Gate->>Scope: Request-owned provider selection (private identity map)
    Scope->>Scope: Existing User / signed Agent provider or deliberate null
    Scope->>Admission: Validated references + resolved snapshot
    Admission->>Availability: Fresh authoritative check for each required Feature
    alt All restrictions pass
        Scope->>Work: Sanitized downstream SecurityContext
    else Ordinary OFF / unmet PREVIEW
        Gate-->>Router: ResourceNotFound; outer Common safe boundary emits generic 404
    else Invalid metadata / context / binding / operational failure
        Gate-->>Router: Fault; outer Common safe boundary emits safe 500
    end
```

Source owners: [FeatureMiddleware](../src/Adapter/Middleware/Psr15/FeatureMiddleware.php),
[RequestAuthentication](../src/Adapter/Middleware/Psr15/RequestAuthentication.php),
[FeatureAdmission](../src/Application/AccessControl/Feature/Admission/FeatureAdmission.php),
[effective declarations](feature-references.md) and [availability](feature-availability.md).
The diagram describes this boundary, not consumer storage, authentication or bootstrap qualification.

## Explicit binding

Construct `FeatureRequirements` once for the actual selected action using `SelectedAction::method()`, `invokable()`
or `closure()`. It accumulates validated class/method/interface/ancestor/overridden and explicit route names using
the supplier, not another merger. The middleware's trusted `Closure(ServerRequestInterface): SelectedAction` must
identify the actual serving native action. A different action fails before authentication or protected work.
Never source this selection from client parameters, copied attributes or a route name supplied by the caller.

```php
use Fight\AccessControl\Adapter\Middleware\Psr15\FeatureMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthentication;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;

$authentication = new RequestAuthentication($authorityFactory);
$selected = SelectedAction::method(ReportAction::class, 'handle');
$requirements = new FeatureRequirements($selected, 'report-rollout');
$feature = new FeatureMiddleware($requirements, $trustedSelectedAction, $authentication, new FeatureAdmission($availability));
```

`$authorityFactory` is the existing trusted `Closure(ServerRequestInterface): ?RequestAuthority` composition described
in [Permission admission](http-permission-admission.md#inward-contract-and-trusted-composition). Create fresh actual
User/session or signed Agent providers on each factory invocation. Bind signatures to the actual incoming method,
authority, path, query and body. Missing credentials may deliberately select null; malformed/conflicting selections,
unknown provider kinds and storage/provider exceptions must not become null. No new principal resolver or scheme is
provided. A forged `SecurityContext` request attribute is erased, never used as authentication.

For Slim, bind the middleware directly to the native Route holding the same callable. The trusted selector can read
`RouteContext::fromRequest($request)->getRoute()` and verify both the exact bound Route and its `getCallable()` against
the immutable registered callable before returning the corresponding SelectedAction. Missing/changed/unsupported
callables must throw, not return empty requirements. Add validation first and Feature middleware last: Slim route
middleware is LIFO. Add routing middleware, then Common's safe boundary outermost. Qualified forms are closure,
invokable class-string and `ClassName:method`, including inherited/overridden concrete methods. Denial precedes the
qualified lazy controller construction, validators and action; eager consumer/container construction is not protected.

For Yii 3, bind the same immutable action definition to `Route::action()` and its Feature middleware at trusted route
construction. Use `$feature->process(...)` in the route's middleware list **before** protected validation. The explicit
binding is the association: do not reconstruct an action from untrusted arguments or pretend CurrentRoute exposes a
callable getter. The fixture binds closures and `[ClassName::class, 'method']`, including inherited/overridden methods.
Create a fresh Router and CurrentRoute for every incoming/nested request, retaining only immutable route definitions.
CurrentRoute is single-assignment; do not retain it in a reused factory/container. Arbitrary invokable/container
strategies and universal pre-construction protection are not qualified. Native unmatched/method routing remains native.

## Restrictions, faults and safe output

Every explicit nonempty check reuses FeatureAvailability's authoritative reads and exact canonical name/stored
Permission-ID binding validation at **every** status. OFF denies everyone; PREVIEW requires that bound ID in the
captured User Role-derived or Agent direct Permission snapshot; ON permits anonymous Feature passage. A same-name
Permission with a different ID neither grants PREVIEW nor repairs a binding. Multiple requirements are conjunctive.
A Feature with no effective requirements adds no restriction and selects no authentication; it is not public
Permission admission. Fresh settings do not refresh captured grants, cancel work or create a live/background lease.

Only ordinary false availability throws `FeatureUnavailable`; this gate alone translates that outcome to Common
`ResourceNotFound`, producing a fixed generic 404. Missing Features, broken/mismatched bindings, invalid declarations,
unsupported contexts and operational/native faults remain safe server faults. This is deliberately **not** the older
optional consumer concealment of broken bindings described in the historical availability guide. Known authentication
refusals retain Common's typed 401 behavior and require valid consumer-selected challenges. No classification uses
exception text, code or cause. Dependency failures at trusted selected-action resolution/validation, Feature evaluation,
trusted authority selection and Feature provider resolution are retained as causes of operational faults, not passed
outward as unrelated declared safe outcomes (for example, a repository's `ResourceNotFound` is not Feature denial).
Only the authority boundary preserves known typed authentication refusals; neither target selection nor an evaluation
dependency can declare an authentication outcome. These catches
do not surround downstream validation/action work, whose own declared outcomes remain Common's responsibility.
No Feature name/status/bound ID/private diagnostic enters refusal output.

Compose the existing Common `SafeJSendErrorMiddleware`, `SafeHttpErrorPolicy`, required redaction-aware diagnostics
owner, PSR-17 JSend factory and optional validated projector described in the Permission guide. There is one reporting
attempt, no failed-sink retry, secondary renderer/logger, debug fallback or error-framework fork. Current JSON/header/
challenge/fallback behavior is retained; selected JSON ignores HTML Accept, and outer/non-JSON handling is not thereby
safe. MCP/OAuth retain their own envelopes outside this ordinary boundary.

## Cooperating gates and request lifetime

Pass the **same RequestAuthentication object** as the Permission middleware's `authority` argument and Feature
middleware's `authentication`. Install the gates adjacently, before request-rewriting validators or action middleware.
Either order is supported. The private WeakMap follows only the exact forwarded PSR request object during the active
chain; it never trusts transferable request attributes. PUBLIC Permission admission skips authentication but does not
erase a context selected by another cooperating gate. Both restrictions still apply: ON cannot bypass Permission.
Actual providers cache one request snapshot, so a second gate does not replay a signed Agent nonce.

Do not interpose middleware that clones/rewrites the PSR request between these gates: such a request intentionally
has no trusted shared entry and requires fresh selection rather than accepting copied authority. Use adjacent gates
or a consumer-owned qualified composition; arbitrary request mutation is not an implicit trusted handoff. A nested
independent dispatch must use its own incoming request/provider/nonce. Copied parent contexts confer no authority.
Finally-scoped state is removed after success or exception; reused middleware/containers do not carry principals
into sequential requests. Providers and signed requests must not be captured across factory invocations.

## Evidence and installation limits

[Shared admission tests](../tests/Application/AccessControl/Feature/Admission/FeatureAdmissionTest.php) and
[separate Slim/Yii journeys](../tests/Adapter/Middleware/Psr15/FeatureMiddlewareTest.php) cover effective bindings,
status/fault/construction/lifetime behavior, real User/Agent providers, independent Feature selection and cooperating
nonce use. Existing availability/conformance and safe-boundary tests remain authoritative for unchanged evaluator
identity/freshness and Common failure behavior. No checking business Command/Query/Event, transaction or audit is added;
normal authentication's required effects remain separate.

Composer requires Common `^1.4.2` and PSR contracts; native frameworks remain optional dev fixtures/suggestions.
Selected sufficient tagged minimum and ordinary lanes are recorded in the TASK handoff, not inferred from class
existence. Package/native fixtures do not qualify actual consumer routing coverage, scanner/code identity, persistence/
cache/locks, authentication/crypto/nonce stores, record/tenant scope, diagnostics redaction, proxies, bootstrap,
outer HTML/error handling or deployment. Independent Review and behavioral QA remain separate obligations.
