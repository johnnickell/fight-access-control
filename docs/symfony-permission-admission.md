# Optional Symfony Permission admission

TASK-00104 adds explicit native `Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber`, reusing the
[inward HTTP admission contracts](http-permission-admission.md#inward-contract-and-trusted-composition).
It installs no routes, firewall, token issuer, container services, grants, voter, logger or renderer. Symfony is
optional: install the selected components explicitly. Slim and Yii application runtimes are not required.
This qualifies controlled HttpKernel/component composition, **not** a booted FrameworkBundle application or consumer
security/bootstrap/deployment. The combined five-framework release remains unfinished and unversioned.

## Trusted routes and explicit registration

Use PHP `RouteCollection` definitions with a single `PermissionDeclaration` default keyed by its class name.
Every route in this selected ordinary HTTP kernel needs a deliberate declaration, including public routes.
`PUBLIC`, `AUTHENTICATED` and conjunctive `PROTECTED` retain the shared semantics. Null/missing, raw strings, arrays
of declarations and foreign objects fail closed as configuration faults. An attribute alone is not a declaration.
Invalid Permission lists fail when the shared declaration is constructed; there is no attribute override/union policy.

```php
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();
$routes->add('reports', new Route('/reports/{id}', [
    '_controller' => ReportAction::class.'::handle',
    PermissionDeclaration::class => new PermissionDeclaration(
        AdmissionMode::PROTECTED,
        [PermissionName::fromString('REPORT_READ')]
    ),
], methods: ['GET'])); // REPORT_READ is an illustrative consumer-owned Permission, not an installed grant.
$stack = new RequestStack();
$matcher = new UrlMatcher($routes, new RequestContext());
$events->addSubscriber(new RouterListener($matcher, $stack));
$events->addSubscriber(new PermissionSubscriber($matcher, $authorityFactory));
// Compose HttpKernel with this dispatcher, the same RequestStack and native resolvers.
```

`$authorityFactory` is a trusted `Closure(Request): ?RequestAuthority`, not a package service ID or callable from
request metadata. It constructs fresh actual User/Agent providers as described in the shared guide. Return null only
for genuinely absent selected authentication. Provider/storage/configuration exceptions remain operational failures;
a Symfony security token, role label, query/header, route argument or copied SecurityContext is never a grant.
No fallback identity is selected. Agent request binding includes the actual method, authority, path, canonical query
and body under consumer-owned proxy/canonicalization rules. Controlled crypto/persistence doubles are not production proof.

The subscriber independently calls the **same trusted request matcher** after native routing, then checks exact
`_route` and raw `_controller` association. Declarations come from the match result, never the request's copied
PermissionDeclaration attribute. This closes RouterListener's deliberate `_controller`-already-present shortcut:
stale/forged target attributes cannot select different requirements or a public callback. Two routes sharing a
callback keep distinct requirements. The matcher and routes must be immutable during service, deterministic and
side-effect-free; matching runs twice. Reserve `_route`, `_controller` and the declaration key for trusted defaults,
not client-controlled placeholders. No rerouting/controller replacement may occur after admission without its own
qualified admission. Do not treat arbitrary deserialized request attributes or route-loader metadata as trusted.

Qualified route configuration is PHP RouteCollection + UrlMatcher. Compiled route-cache export, attribute route loaders,
service-ID/alias controller resolvers and consumer container/firewall composition need their own qualification.
The interface accepts a trusted `RequestMatcherInterface`; that is not a claim every implementation has been tested.

## Native order, controllers and authorization attributes

| Boundary | Qualified order |
| --- | --- |
| Native RouterListener | `kernel.request` priority 32 |
| AccessControl PermissionSubscriber | `kernel.request` priority 16, main **and** subrequests |
| ControllerResolver | After all request listeners; may instantiate a class here |
| Common SymfonyValidationSubscriber | `kernel.controller`, priority 0 |
| Native ArgumentResolver | After controller listeners |
| Native IsGranted attributes | `kernel.controller_arguments`; Symfony 8.1 ControllerAttributesListener at -10000 dispatches the IsGranted-specific events |
| Action and response listeners | Only after successful earlier stages; normal kernel.response still runs |

Tests instrument construction, factory/provider resolution, validation, arguments, native authorization and action.
Denied admission performs none of the protected construction/validation/argument/native-attribute/action work.
`kernel.controller` alone would be too late for this construction guarantee. Objects already instantiated by bootstrap
are necessarily outside it. Consumer listeners with higher priority or eager container resolution also precede it.
Consumers must establish trusted authentication context before priority 16; Symfony's default priority-8 firewall
is **not** implicitly an authentication supplier for this factory. No firewall order/bootstrap is installed here.

Qualified controller forms: `ClassName::method` string, `[ClassName::class, 'method']`, invokable class-string,
`[$object, 'method']`, invokable object and closure. Native route arguments reach the action. Invalid/non-callable
protected controllers produce safe 500, not public fallback. Function strings, static methods, magic callbacks,
service aliases and controller-swapping listeners are outside the qualified forms. A public declaration is deliberate,
not detection of absent attributes. Alternate direct calls/buses/workers retain independent authorization.

Class and method `#[IsGranted]` string attributes compose conjunctively **in addition to** AccessControl admission;
closures and named argument subjects are also exercised. These native checks do not replace early coarse admission.
Use deliberately supported native `statusCode: 403` for HTTP refusal: Symfony then throws an exact HttpException,
which Common's type policy recognizes. Default IsGranted throws Security Core AccessDeniedException; without separately
qualified explicit translation, Common treats it as unknown **500**, not guessed 403. No security exception/firewall
listener, role-to-Permission voter, expression evaluator or blanket Symfony-security support is supplied. Native
expression/method-filter/custom attribute policies need separate consumer qualification. Common's actual Fight
`#[Validation]` behavior is exercised on object methods/invokables; its subscriber does not validate closures.
Native validation/IsGranted may still reject an admitted request before action; native IsGranted is after arguments,
so consumer record checks cannot claim early argument-resolution protection.

## Request, subrequest and continuation lifetimes

The subscriber is stateless and reusable. Each non-public request invokes the trusted factory anew; providers must
not be captured as cached singletons. Admission removes any incoming SecurityContext and sets only the admitted
snapshot. A public route erases it without resolving authentication. A failed admission leaves no selected context.
Symfony pops its RequestStack on success and handled exception; repeated requests use fresh authority, including when
the same completed Request object is reused. The retained attribute is a **short-request snapshot**, not future authority.

Use a **distinct Request object** for every nested subrequest. It independently enters routing/admission and requires
explicitly selected trusted authentication. A duplicated parent context is ignored, not a delegation contract. When
changing a duplicated request's target, clear inherited routing attributes so RouterListener can route it; conflicting
route/controller metadata fails closed. Reentrant dispatch of the very same mutable Request object is not supported.
A child refusal or public child does not mutate the parent's selected context; native parent routing context is restored.
There is no automatic caller identity propagation or package-defined delegated handoff.

Multiple requirements and cooperating downstream operations use the one admitted snapshot/provider; do not authenticate
again for each listener. Agent authentication consumes one nonce. A fresh provider replaying that consumed signed request
in a subrequest is refused; independently authenticated children need their own valid nonce. Later live/background work
uses [current continuation authority](agent-continuation-authorization.md) at its owner, never an HTTP lifetime grant.

## One Common native safe error owner

Select Common v1.4.0's canonical `Adapter\Http\Symfony\EventSubscriber\SafeSymfonyExceptionSubscriber`, with its
`Controller\SafeErrorController`, `Adapter\Http\Error\SafeHttpErrorPolicy`, required redaction-aware
`SafeHttpDiagnostics` and optional `SafeHttpProjector`. Register `onKernelException` explicitly on
`kernel.exception` at **64**, before Symfony ErrorListener's `logKernelException` at **0**. It is deliberately not an
autoconfigured subscriber. Exclude both legacy exception subscribers/controllers and PSR error wrappers on this path.
Qualify any higher-priority listeners, attribute-event handlers and outer logging before permitting private failures.

On selected JSON failures, setting the native response stops exception propagation: tests prove one diagnostic attempt
and zero calls to the competing actual ErrorListener logger entry point. The fixture's sink passes only an exception
type into its recorded logger payload, not Throwable/messages/causes/credentials. A production sink must perform its
own trusted redaction **before** any formatter/logger. A failed sink is not evidence of delivery and is not retried.

The shared RequestAuthority translates only known User/Agent refusals; invalid/incomplete Agent snapshots and opaque
provider/storage failures remain 500. Common then classifies known Application and native HTTP types, not arbitrary
codes/messages/causes. Projectors may select finite public data and validated metadata but cannot reclassify unknown
failures. AuthenticationRequired needs a consumer-selected challenge; there is no universal Bearer policy. Known
native 401/405 challenges/Allow and canonical string Retry-After seconds are preserved; unrelated headers are dropped.
Malformed/duplicate metadata, missing required challenge/Allow, projector/sink/encoding/render faults fall back to
fixed generic 500 with one report attempt. Common v1.4.0 accepts native Retry-After only as a canonical decimal **string**;
Symfony's integer-valued TooManyRequestsHttpException header yields safe 500. Select string seconds explicitly where
using this path; this package does not silently coerce or repair foreign metadata. Tests inject one real envelope
encoder failure in an isolated process and observe successful native fallback rendering, without modifying Common source.

Negotiation is Common's actual native policy: exact XHR wins; otherwise application/json must precede text/html in
Symfony's quality-ordered acceptable types. Missing Accept, wildcard or HTML preference delegates. Those paths reach
native logging/rendering and **are not claimed redacted/safe**. Consumer HTML/error-controller/debug and response listeners
need independent qualification. Safe JSON ignores client debug intent. Successful and failed native responses still
traverse kernel.response; this adapter does not wrap response bodies or take over headers.

Keep MCP/OAuth routes outside this selected ordinary-HTTP composition (for example, independently composed kernels/
entry points). Neither PUBLIC nor an OAuth scope is protocol exclusion or a Permission grant. Preserve MCP JSON-RPC,
exact-class failure/concealment and OAuth's own challenges/empty rejections; do not send them through Common's ordinary
JSend handler. This package installs no protocol or global allowlist and changes neither protocol contract.

## Qualified tags and remaining obligations

These **selected sufficient minimums** are the installed tagged lane, not a claim about the earliest theoretically
compatible release. Common remains `^1.4`; native components are dev fixtures plus optional Composer suggestions.
Security HTTP is optional even for Symfony admission: a core-only fixture passes with it absent. Another exact-minimum
fixture exercises actual native User/Agent/order/error behavior with Slim/Yii application packages absent.

| Component / selected range | Tested tag source |
| --- | --- |
| Common `^1.4` | v1.4.0 `87b9580c072f3149d574e09865daa79620c92118` |
| Event Dispatcher `^8.1.5` | v8.1.5 `7458da64220376b2e0dc2d8451bf43382c1ad297` |
| HttpFoundation `^8.1.9` | v8.1.9 `7850897fb8659f98491b03be79ac48600bdaf9ab` |
| HttpKernel `^8.1.9` | v8.1.9 `55c6935bb4c004a72b55f723e79d5404881dcad3` |
| Routing `^8.1.9` | v8.1.9 `edb06fb6ced3c1faaaed74c1c6c0f176a614c330` |
| Security HTTP `^8.1.9` (optional attributes) | v8.1.9 `f3733837bb5ac0b0eed6895b14e374892547c97e` |
| Security Core (transitive installed identity) | v8.1.9 `41968f1068f89edf881ef4ae0e691e0d9dd5d448` |

All seven installed package file sets match upstream tag archives; exact tag refs, manifests, locks, traces and the
patched PHP **8.5.12** runtime receipt are retained with TASK evidence. Native components require PHP 8.4.1+, but this
package requires PHP 8.5+ and qualifies the patched 8.5.12 runtime, not older insecure patches. Semver ranges are support
bounds, not proof of future releases or every transitive resolver combination. No development snapshot or alias is used.
Normal mirrored Adapter tests, architecture/style/static analysis/default tests and exact owned statement coverage apply.
Actual consumer routes/bootstrap/firewall, keys/crypto, persistence, scope/record checks, sinks, proxies, HTML handling and
deployed runtime remain unqualified. Independent Review and nonvisual behavioral QA are required before acceptance.
