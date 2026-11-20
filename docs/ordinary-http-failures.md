# Ordinary HTTP failure contracts (Unreleased)

Ordinary HTTP admission and error handling are **separately selected**. Use Common `^1.5.1` and its canonical
safe policy/diagnostics/projector through the [PSR-15](http-permission-admission.md),
[Symfony](symfony-permission-admission.md), [Laravel](laravel-permission-admission.md) or
[CodeIgniter 4](codeigniter-permission-admission.md) composition. No error handler is automatically installed.
Common owns classification, redaction-aware reporting and finite public presentation; AccessControl adds no
second logger, exception taxonomy or global response wrapper.

## Intent, not diagnostics

| Declared failure | Ordinary safe HTTP status | Default public message |
| --- | --- | --- |
| Common Application `ResourceNotFound` (including subtypes) | 404 | Resource not found. |
| Common Application `StateConflict` | 409 | State conflict. |
| Common Application `PreconditionFailed` | 412 | Precondition failed. |
| Common Application `RuleViolation` | 422 | Rule violation. |
| Common structured `ValidationException` / Application `InvalidInput` | 400 | Invalid input. |
| Internal identity/result violation, generic Domain `LookupException`, unknown fault | 500 | Internal server error. |

Client failures use JSend `fail` with `data.message`; server failures use `error` with `message`. Classification
never reads exception messages, integer codes or private causes. An unknown failure with a not-found message/code/
cause remains 500. Domain types never depend on Application types. Required-resource use cases declare their
[intentional Application absence](required-resource-queries.md); a nullable repository alone is not HTTP intent.

Structured validation remains structured inward. Do not pass `getErrors()`, submitted values or raw field names to
a logger or response. A consumer projector may author a fixed admitted field/code vocabulary, for example
`['fields' => ['name' => ['invalid']]]`; it must not copy arbitrary dependency errors. Public data must be plain,
finite, depth-bounded JSON. A projector cannot change the classified status. Missing/invalid required challenge or
Allow metadata, misapplied headers, invalid projection, sink or renderer failure selects Common's safe 500 fallback.
There is no universal Bearer challenge or automatic publication of application retry hints.

One diagnostic **attempt** precedes projection/rendering. The trusted diagnostics implementation must redact before
any logger/formatter receives private messages, causes, stack arguments or context; forwarding a Throwable under
PSR-3 `exception` is not redaction. Failed reporting proves no delivery and is not retried. Package fixture sinks
record only an allowlisted class name; that does not qualify an actual consumer sink.

## Current admission and historical safe-error qualification

Use the linked framework guides for current constructors and registration. An authority factory is trusted
configuration: return `RequestAuthority` or genuine absence, never a copied SecurityContext or principal.
Static inference does not validate native route, action or filter metadata. See [TASK-00146](../planning/tasks/00146-TASK.md)
for the earlier safe-error qualification checkpoint.

## Native selection and lifetime limits

- PSR selected paths emit JSON regardless of Accept. A failed primary and fallback response factory leaves only
  Common's sanitized cause-free terminal throw; outer host handling must itself be qualified.
- Symfony uses its actual JSON/XHR negotiation and priority-64 safe listener before the priority-0 native logger.
  Construct the selected HttpKernel with **`handleAllThrowables: true`** to dispatch PHP Errors/TypeErrors to that
  listener. The native default false propagates Errors without safe exception dispatch. Do not infer containment
  merely from registering the listener. Responses still traverse `kernel.response` and normal native finalization.
- Laravel retains `expectsJson()` negotiation and its own response lifecycle. CI4's explicitly selected JSON loop
  is not its global HTML exception handler. Arbitrary earlier listeners/loggers, bootstrap, outer handling and
  later response/send observers are outside these safe boundaries.
- Missing/wildcard/HTML negotiation delegated by Symfony/Laravel is **not** automatically safe. Higher-priority
  listeners and production debug/logging configuration require independent consumer qualification.
- Every request/subrequest/nested dispatch uses fresh request-owned providers and trusted selection; no copied
  parent identity or automatic delegation. CI4's process-global bootstrap is not qualified for reentrant nested
  application dispatch. Multiple requirements share the one short-request snapshot/Agent nonce; continuation
  revalidates through its [separate owner](agent-continuation-authorization.md).
- MCP JSON-RPC/exact-class Tool failures and OAuth empty rejections/challenges stay outside ordinary HTTP/JSend
  admission and errors. PUBLIC is not protocol exclusion; OAuth scopes and supplied IDs are not Permission grants.

## Consumer obligations and evidence bounds

Current selected native lower bounds are Slim 4.15.3/PSR7 1.8.0; Yii Router 4.0.2/FastRoute 4.0.3/Dispatcher 5.4.0/
DI 1.4.1/Injector 1.2.2; Symfony Event Dispatcher 8.1.5 and HttpFoundation/HttpKernel/Routing/optional Security HTTP
8.1.11; Laravel 13.40.0; CI4 4.7.6. Routing and optional Security HTTP floors are raised to 8.1.11 after advisory
checks of the older lane. The current AccessControl floor for Common is `^1.5.1`.

Update nullable-result callers and generic LookupException-to-404 catches directly to the actual current use-case
contract. Keep unknown/internal faults as server failures; do not add a compatibility reader, fallback grant or
another renderer. Select Common `^1.5.1`, supply real request-owned authentication, safe diagnostics, finite public
projections and required scheme metadata, and qualify actual middleware/listener order and final responses.

CI4 [native Permission checks](codeigniter-permission-admission.md#current-verification-boundary) do not qualify
Feature-native or combined routing, ordering, downstream interception, bootstrap or reset. Follow the
[Feature qualification boundary](codeigniter-feature-admission.md#current-verification-boundary--task-00168-amendment).

Maintained native fixtures cover Slim/Yii/Symfony and present Laravel category/privacy paths, safe metadata,
report/fallback/order, fresh request/nonce behavior and protocol exclusion. Supported-minimum, ordinary and optional
no-dev lanes are package composition evidence, not every version in a semver range. Local Work evidence records
exact locks, source/tag identities, counts, coverage and warnings; independent Review/behavioral QA remain required.
No actual consumer persistence/crypto/target scope/sink/bootstrap/proxy/HTML/deployment, all-five-framework release,
new business write, grant, automatic installation or release is qualified by these fixtures.
