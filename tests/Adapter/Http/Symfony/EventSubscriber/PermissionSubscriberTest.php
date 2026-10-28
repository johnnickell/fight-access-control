<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Service\AdmissionEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

#[CoversClass(PermissionSubscriber::class)]
#[CoversClass(PermissionAdmission::class)]
#[CoversClass(RequestAuthority::class)]
#[CoversClass(PermissionDeclaration::class)]
final class PermissionSubscriberTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forms(): iterable
    {
        $forms = ['method-string', 'method-array', 'invokable-class', 'method-object', 'invokable-object', 'closure'];
        foreach ($forms as $form) {
            yield $form => [$form];
        }
    }

    #[DataProvider('forms')]
    public function test_real_kernel_user_admission_precedes_construction_validation_arguments_and_native_attributes(
        string $form
    ): void {
        NativeController::$trace = new NativeTrace();
        $controller = match ($form) {
            'method-string' => NativeController::class.'::handle',
            'method-array' => [NativeController::class, 'handle'],
            'invokable-class' => NativeController::class,
            'method-object' => [new NativeController(), 'handle'],
            'invokable-object' => new NativeController(),
            default => #[IsGranted('CONSUMER_RECORD', subject: 'id', statusCode: 403)]
                static function (Request $request, string $id): Response {
                    NativeController::$trace->events[] = 'action';
                    NativeController::$trace->contexts[] = $request->attributes->get(SecurityContext::class);

                    return new Response('', 204, ['X-Action' => $id]);
                }
        };
        $environment = new AdmissionEnvironment();
        $grant = false;
        $native = new NativeKernel(function () use ($environment, &$grant, &$native): RequestAuthority {
            $native->trace->events[] = 'authority';

            return $environment->user($grant);
        }, $this->protected(), $controller);
        $denied = $native->request($this->request());
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(['authority', 'report', 'response'], $native->trace->events);
        $grant = true;
        $native->trace->events = [];
        $admitted = $native->request($this->request());
        self::assertSame(204, $admitted->getStatusCode());
        self::assertSame('123', $admitted->headers->get('X-Action'));
        self::assertSame('preserved', $admitted->headers->get('X-Lifecycle'));
        $events = $native->trace->snapshot();
        self::assertSame('authority', $events[0]);
        self::assertContains('is-granted:CONSUMER_RECORD:123', $events);
        self::assertLessThan(array_search('arguments', $events, true), array_search('validation', $events, true));
        self::assertLessThan(array_search('action', $events, true), array_search('arguments', $events, true));
        self::assertSame(
            in_array($form, ['method-string', 'method-array', 'invokable-class'], true),
            in_array('construct', $events, true)
        );
        self::assertInstanceOf(SecurityContext::class, $native->trace->contexts[0]);
        self::assertSame(2, $environment->resolutions);
        self::assertNull($native->stack->getCurrentRequest());
        self::assertNotContains('raw-logger', $events);
    }

    public function test_native_validation_and_is_granted_remain_independent_after_coarse_admission(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeKernel(static fn(): RequestAuthority => $environment->user(), $this->protected());
        $invalid = $this->request();
        $invalid->query->set('name', '123');
        self::assertSame(400, $native->request($invalid)->getStatusCode());
        self::assertSame(['construct', 'validation', 'report', 'response'], $native->trace->events);
        $native->trace->events = [];
        $native->trace->nativeGranted = false;
        self::assertSame(403, $native->request($this->request())->getStatusCode());
        self::assertContains('arguments', $native->trace->events);
        self::assertNotContains('action', $native->trace->events);
        self::assertNotContains('raw-logger', $native->trace->events);
        $native->trace->nativeGranted = true;
        $native->trace->events = [];
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertContains('is-granted:CONSUMER_SCOPE:none', $native->trace->events);
        self::assertContains('is-granted:CONSUMER_RECORD:123', $native->trace->events);
    }

    public function test_default_security_exception_is_not_implicitly_an_http_permission_refusal(): void
    {
        $environment = new AdmissionEnvironment();
        $controller = #[IsGranted('CONSUMER_SCOPE')]
            static fn(): Response => new Response('', 204);
        $native = new NativeKernel(
            static fn(): RequestAuthority => $environment->user(),
            $this->protected(),
            $controller
        );
        $native->trace->nativeGranted = false;
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertCount(1, $native->trace->reports);
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    public function test_sequential_requests_and_exception_reset_use_fresh_actual_user_authority(): void
    {
        $environment = new AdmissionEnvironment();
        $valid = true;
        $fault = null;
        $native = new NativeKernel(static function () use ($environment, &$valid, &$fault): RequestAuthority {
            return $environment->user(valid: $valid, fault: $fault);
        }, $this->protected(), projector: $this->challenge());
        $request = $this->request();
        self::assertSame(204, $native->request($request)->getStatusCode());
        $first = $request->attributes->get(SecurityContext::class);
        $valid = false;
        $refused = $native->request($request);
        self::assertSame(401, $refused->getStatusCode());
        self::assertSame('Signature realm="example"', $refused->headers->get('WWW-Authenticate'));
        self::assertFalse($request->attributes->has(SecurityContext::class));
        $valid = true;
        $fault = new RuntimeException('PRIVATE STORAGE', 401, new AuthenticationRequired('PRIVATE CAUSE'));
        $broken = $native->request($request);
        self::assertSame(500, $broken->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $broken->getContent());
        $fault = null;
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertNotSame($first, $request->attributes->get(SecurityContext::class));
        self::assertCount(2, $native->trace->contexts);
        self::assertSame(4, $environment->resolutions);
        self::assertNull($native->stack->getCurrentRequest());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'missing' => [null];
        yield 'raw-name' => ['AGENT_PROFILE_READ'];
        yield 'ambiguous' => [[
            new PermissionDeclaration(AdmissionMode::PUBLIC),
            new PermissionDeclaration(AdmissionMode::AUTHENTICATED)
        ]];
        yield 'foreign-object' => [new stdClass()];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_missing_malformed_and_ambiguous_native_declarations_fail_before_providers(
        mixed $declaration
    ): void {
        $calls = 0;
        $native = new NativeKernel(static function () use (&$calls): ?RequestAuthority {
            ++$calls;

            return null;
        }, $declaration);
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame(['report', 'response'], $native->trace->events);
    }

    public function test_copied_metadata_and_injected_native_targets_cannot_replace_trusted_route_composition(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeKernel(static fn(): RequestAuthority => $environment->user(false), $this->protected());
        $forged = $this->request();
        $forged->attributes->set(PermissionDeclaration::class, new PermissionDeclaration(AdmissionMode::PUBLIC));
        $forged->attributes->set(SecurityContext::class, $environment->user()->resolve());
        $forged->headers->set('X-Permissions', 'AGENT_PROFILE_READ');
        self::assertSame(403, $native->request($forged)->getStatusCode());
        self::assertFalse($forged->attributes->has(SecurityContext::class));
        $forged->attributes->set('_controller', static fn(): Response => new Response('bypass'));
        self::assertSame(500, $native->request($forged)->getStatusCode());
        $wrongRoute = $this->request();
        $wrongRoute->attributes->set('_controller', NativeController::class.'::handle');
        $wrongRoute->attributes->set('_route', 'public');
        self::assertSame(500, $native->request($wrongRoute)->getStatusCode());
        self::assertNotContains('construct', $native->trace->events);
        self::assertNotContains('action', $native->trace->events);
    }

    public function test_public_and_authenticated_modes_do_not_invent_permission_grants(): void
    {
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $native = new NativeKernel(static function () use ($environment, &$calls): RequestAuthority {
            ++$calls;

            return $environment->user(false);
        }, new PermissionDeclaration(AdmissionMode::AUTHENTICATED));
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        $copy = $this->request('/public');
        $copy->attributes->set(SecurityContext::class, $native->trace->contexts[0]);
        self::assertSame(202, $native->request($copy)->getStatusCode());
        self::assertFalse($copy->attributes->has(SecurityContext::class));
        self::assertSame(1, $calls);
        self::assertSame(404, $native->request($this->request('/missing'))->getStatusCode());
        $method = $this->request('/protected/123', 'DELETE');
        $response = $native->request($method);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
        self::assertSame(1, $calls);
    }

    public function test_two_native_routes_sharing_a_callback_keep_distinct_conjunctive_requirements(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeKernel(static fn(): RequestAuthority => $environment->user(), $this->protected());
        $native->routes->add('restricted', new Route('/restricted/{id}', [
            '_controller'                => NativeController::class.'::handle',
            PermissionDeclaration::class => new PermissionDeclaration(AdmissionMode::PROTECTED, [
                PermissionName::fromString('AGENT_PROFILE_READ'),
                PermissionName::fromString('ACCOUNT_MANAGE')
            ])
        ]));
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame(403, $native->request($this->request('/restricted/123'))->getStatusCode());
        self::assertCount(1, $native->trace->contexts);
        self::assertSame(2, $environment->resolutions);
    }

    public function test_unsupported_controller_and_dynamic_provider_configuration_faults_fail_closed(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeKernel(
            static fn(): RequestAuthority => $environment->user(),
            $this->protected(),
            new stdClass()
        );
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertNotContains('validation', $native->trace->events);
        $native = new NativeKernel(static function (): never {
            throw new RuntimeException('PRIVATE FACTORY', 403);
        }, $this->protected());
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(['report', 'response'], $native->trace->events);
        $native = new NativeKernel(
            static fn(): ?RequestAuthority => null,
            $this->protected(),
            projector: $this->challenge()
        );
        self::assertSame(401, $native->request($this->request())->getStatusCode());
    }

    public function test_nested_subrequests_ignore_parent_authority_and_restore_native_parent_context(): void
    {
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $native = new NativeKernel(static function () use ($environment, &$calls): ?RequestAuthority {
            ++$calls;

            return $calls === 1 ? $environment->user() : null;
        }, $this->protected(), projector: $this->challenge());
        NativeController::$work = function (Request $parent) use ($native): Response {
            $context = $parent->attributes->get(SecurityContext::class);
            $child = $parent->duplicate();
            self::assertSame(401, $native->request($child, HttpKernel::SUB_REQUEST)->getStatusCode());
            self::assertFalse($child->attributes->has(SecurityContext::class));
            self::assertSame($parent, $native->stack->getCurrentRequest());
            self::assertSame($context, $parent->attributes->get(SecurityContext::class));
            $public = $this->request('/public');
            $public->attributes->set(SecurityContext::class, $context);
            self::assertSame(202, $native->request($public, HttpKernel::SUB_REQUEST)->getStatusCode());
            self::assertFalse($public->attributes->has(SecurityContext::class));

            return new Response('', 204);
        };
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame(2, $calls);
        self::assertCount(1, $native->trace->contexts);
        self::assertNull($native->stack->getCurrentRequest());
    }

    public function test_real_native_agent_direct_permissions_nonce_lifetime_and_operational_snapshot_faults(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $broken = false;
        $native = new NativeKernel(static function (Request $request) use ($authority, &$broken): RequestAuthority {
            return $authority->create($request, $broken);
        }, $this->protected());
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame('GET', $environment->request->getMethod());
        self::assertSame('/protected/123', $environment->request->getPath());
        self::assertSame('example.test', $environment->request->getAuthority());
        self::assertSame('name=Alice', $environment->request->getNormalizedQuery());
        self::assertSame(1, $environment->nonces->consumptionCalls());
        self::assertTrue($native->trace->contexts[0]->hasPermission(PermissionName::fromString('AGENT_PROFILE_READ')));
        self::assertSame(1, $environment->nonces->consumptionCalls());
        $agent = $environment->storage->agents->all()[0];
        $read = $environment->permissions->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
        self::assertNotNull($read);
        $environment->storage->agents->restoreSnapshot([$agent->revokePermission($read->getId(), $environment->now())]);
        self::assertSame(403, $native->request($this->request())->getStatusCode());
        self::assertSame(2, $environment->nonces->consumptionCalls());
        self::assertCount(1, $native->trace->contexts);
        $broken = true;
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(3, $environment->nonces->consumptionCalls());
        self::assertCount(2, $native->trace->reports);
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    public function test_agent_authentication_binds_actual_native_post_body_query_and_authority(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $native = new NativeKernel($authority->create(...), $this->protected());
        $request = Request::create(
            'https://example.test:8443/protected/456?source=native',
            'POST',
            ['name' => 'Alice'],
            server: ['HTTP_ACCEPT' => 'application/json'],
            content: 'name=Alice'
        );
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertSame('POST', $environment->request->getMethod());
        self::assertSame('example.test:8443', $environment->request->getAuthority());
        self::assertSame('/protected/456', $environment->request->getPath());
        self::assertSame('source=native', $environment->request->getNormalizedQuery());
        self::assertSame('name=Alice', $environment->request->getBody());
        self::assertSame(hash('sha256', 'name=Alice'), $environment->request->getBodyDigest());
        self::assertSame(1, $environment->nonces->consumptionCalls());
    }

    public function test_independent_agent_subrequests_need_fresh_nonce_not_automatic_parent_reauthentication(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $replay = false;
        $native = new NativeKernel(static function (Request $request) use ($authority, &$replay): RequestAuthority {
            return $authority->create($request, replay: $replay);
        }, $this->protected(), projector: $this->challenge());
        NativeController::$work = function (Request $parent) use ($native, &$replay): Response {
            if ($native->trace->depth > 0) {
                return new Response('', 204);
            }

            ++$native->trace->depth;
            $replay = true;
            self::assertSame(401, $native->request($parent->duplicate(), HttpKernel::SUB_REQUEST)->getStatusCode());
            $replay = false;
            $fresh = $parent->duplicate();
            self::assertSame(204, $native->request($fresh, HttpKernel::SUB_REQUEST)->getStatusCode());
            self::assertNotSame(
                $parent->attributes->get(SecurityContext::class),
                $fresh->attributes->get(SecurityContext::class)
            );
            --$native->trace->depth;

            return new Response('', 204);
        };
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame(3, $environment->nonces->consumptionCalls());
        self::assertCount(2, $native->trace->contexts);
        self::assertCount(1, $native->trace->reports);
        self::assertNull($native->stack->getCurrentRequest());
    }

    /** @return iterable<string, array{Throwable, int, ?string, ?string}> */
    public static function failures(): iterable
    {
        yield 'permission' => [new PermissionDenied('PRIVATE'), 403, null, null];
        yield 'missing-challenge' => [new AuthenticationRequired('PRIVATE'), 500, null, null];
        yield 'native-challenge' => [new UnauthorizedHttpException('Signature realm="example"', 'PRIVATE'), 401,
            'WWW-Authenticate', 'Signature realm="example"'];
        yield 'allow' => [new MethodNotAllowedHttpException(['GET', 'POST'], 'PRIVATE'), 405, 'Allow', 'GET, POST'];
        yield 'retry' => [new TooManyRequestsHttpException('12', 'PRIVATE'), 429, 'Retry-After', '12'];
        yield 'integer-retry-is-not-string-metadata' => [
            new TooManyRequestsHttpException(12, 'PRIVATE'), 500, null, null
        ];
        yield 'invalid-challenge' => [new UnauthorizedHttpException("Basic\r\nPRIVATE", 'PRIVATE'), 500, null, null];
        yield 'invalid-retry' => [new TooManyRequestsHttpException('tomorrow', 'PRIVATE'), 500, null, null];
        yield 'invalid-allow' => [new HttpException(405, 'PRIVATE', headers: ['Allow' => 'GET,POST']), 500, null, null];
        yield 'duplicate-header' => [new HttpException(401, 'PRIVATE', headers: [
            'WWW-Authenticate' => 'Basic realm="one"', 'www-authenticate' => 'Basic realm="two"'
        ]), 500, null, null];
        yield 'unknown-code-cause' => [
            new RuntimeException('PRIVATE', 403, new PermissionDenied('PRIVATE')),
            500, null, null
        ];
        yield 'unsupported-native-subtype' => [new class (403, 'PRIVATE') extends HttpException {
        }, 500, null, null];
        yield 'native-forbidden' => [
            new AccessDeniedHttpException('PRIVATE', headers: ['X-Private' => 'PRIVATE']), 403, null, null
        ];
        yield 'unsupported-status' => [new HttpException(418, 'PRIVATE'), 500, null, null];
    }

    #[DataProvider('failures')]
    public function test_actual_native_exception_dispatch_reports_once_before_raw_loggers_and_preserves_safe_headers(
        Throwable $failure,
        int $status,
        ?string $header,
        ?string $value
    ): void {
        $environment = new AdmissionEnvironment();
        $native = new NativeKernel(static fn(): RequestAuthority => $environment->user(), $this->protected());
        NativeController::$work = static function () use ($failure): never {
            throw $failure;
        };
        $response = $native->request($this->request());
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
        self::assertFalse($response->headers->has('X-Private'));
        self::assertCount(1, $native->trace->reports);
        self::assertSame(['type' => $failure::class], $native->trace->reports[0]);
        self::assertNotContains('raw-logger', $native->trace->events);
        self::assertNotContains('delegated-html', $native->trace->events);
        if ($header !== null) {
            self::assertSame($value, $response->headers->get($header));
        }
    }

    public function test_public_projection_never_reclassifies_and_faults_fall_back_without_duplicate_reports(): void
    {
        foreach (['public', 'object', 'nonfinite', 'utf8', 'throw', 'sink'] as $mode) {
            $projector = new readonly class ($mode) implements SafeHttpProjector {
                public function __construct(private string $mode)
                {
                }

                public function project(Throwable $failure, int $status): SafeHttpPresentation
                {
                    return match ($this->mode) {
                        'object' => new SafeHttpPresentation(data: ['bad' => new stdClass()]),
                        'nonfinite' => new SafeHttpPresentation(data: ['bad' => INF]),
                        'utf8' => new SafeHttpPresentation(message: "\xFF"),
                        'throw' => throw new RuntimeException('PRIVATE PROJECTOR'),
                        default => new SafeHttpPresentation('Public generic failure.', ['public' => true])
                    };
                }
            };
            $environment = new AdmissionEnvironment();
            $native = new NativeKernel(
                static fn(): RequestAuthority => $environment->user(),
                $this->protected(),
                projector: $projector
            );
            $native->trace->sinkFails = $mode === 'sink';
            NativeController::$work = static function (): never {
                throw new RuntimeException('PRIVATE', 403);
            };
            $response = $native->request($this->request());
            self::assertSame(500, $response->getStatusCode());
            self::assertCount(1, $native->trace->reports);
            self::assertStringNotContainsString('PRIVATE', (string) $response->getContent());
            self::assertNotContains('raw-logger', $native->trace->events);
            $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(
                $mode === 'public' ? 'Public generic failure.' : 'Internal server error.',
                $body['message']
            );
        }
    }

    /** @return iterable<string, array{?string, bool, bool}> */
    public static function negotiation(): iterable
    {
        yield 'JSON' => ['application/json', false, true];
        yield 'JSON preference' => ['text/html;q=0.2, application/json;q=0.9', false, true];
        yield 'HTML preference' => ['application/json;q=0.2, text/html;q=0.9', false, false];
        yield 'missing' => [null, false, false];
        yield 'wildcard' => ['*/*', false, false];
        yield 'XHR wins HTML' => ['text/html', true, true];
    }

    #[DataProvider('negotiation')]
    public function test_canonical_json_xhr_negotiation_and_explicit_non_json_delegation(
        ?string $accept,
        bool $xhr,
        bool $safe
    ): void {
        $native = new NativeKernel(
            static fn(): ?RequestAuthority => null,
            $this->protected(),
            projector: $this->challenge()
        );
        $request = $this->request();
        $request->headers->remove('Accept');
        if ($accept !== null) {
            $request->headers->set('Accept', $accept);
        }

        if ($xhr) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        $response = $native->request($request);
        self::assertSame($safe ? 401 : 500, $response->getStatusCode());
        self::assertCount($safe ? 1 : 0, $native->trace->reports);
        self::assertSame(!$safe, in_array('raw-logger', $native->trace->events, true));
        self::assertSame(!$safe, in_array('delegated-html', $native->trace->events, true));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_real_native_encoder_failure_uses_safe_fallback_without_a_second_report(): void
    {
        require __DIR__.'/FaultingEncoder.php';
        $native = new NativeKernel(
            static fn(): ?RequestAuthority => null,
            $this->protected(),
            projector: $this->challenge()
        );
        EncodingFault::$remaining = 1;
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertSame(2, EncodingFault::$calls);
        self::assertCount(1, $native->trace->reports);
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    public function test_mismatched_router_or_missing_native_controller_is_configuration_failure(): void
    {
        $native = new NativeKernel(static fn(): ?RequestAuthority => null, $this->protected(), null);
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(['report', 'response'], $native->trace->events);
        $native = new NativeKernel(static fn(): ?RequestAuthority => null, $this->protected());
        $native->events->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            $event->getRequest()->attributes->set('_route', 'wrong');
        }, 17);
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(['report', 'response'], $native->trace->events);
    }

    private function protected(): PermissionDeclaration
    {
        return new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('AGENT_PROFILE_READ')]);
    }

    private function request(string $path = '/protected/123', string $method = 'GET'): Request
    {
        return Request::create(
            'https://example.test'.$path.'?name=Alice',
            $method,
            server: ['HTTP_ACCEPT' => 'application/json']
        );
    }

    private function challenge(): SafeHttpProjector
    {
        return new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if ($status === 401) {
                    return new SafeHttpPresentation(
                        metadata: new SafeHttpMetadata(challenge: 'Signature realm="example"')
                    );
                }

                return null;
            }
        };
    }
}
