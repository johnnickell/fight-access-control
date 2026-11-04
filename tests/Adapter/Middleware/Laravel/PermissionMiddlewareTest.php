<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber\NativeAgentAuthority;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[CoversClass(PermissionMiddleware::class)]
#[CoversClass(PermissionAdmission::class)]
#[CoversClass(PermissionDeclaration::class)]
#[CoversClass(RequestAuthority::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PermissionMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function forms(): iterable
    {
        foreach (
            ['closure', 'method-string', 'method-array', 'invokable',
            'static-middleware', 'constructor-middleware'] as $form
        ) {
            yield $form => [$form];
        }
    }

    #[DataProvider('forms')]
    public function test_actual_kernel_router_authority_order_and_supported_controller_middleware(string $form): void
    {
        $native = new NativeApplication();
        $environment = new AdmissionEnvironment();
        $action = match ($form) {
            'method-string' => ControllerWithFormRequest::class.'@handle',
            'method-array' => [ControllerWithFormRequest::class, 'handle'],
            'invokable' => ControllerWithFormRequest::class,
            'static-middleware' => ControllerWithStaticMiddleware::class,
            'constructor-middleware' => ControllerWithConstructorMiddleware::class,
            default => null
        };
        $route = $native->route(action: $action);
        $grant = false;
        $native->protect([[$route, $this->protected()]], function (Request $request) use (
            $native,
            $environment,
            &$grant
        ): RequestAuthority {
            $native->trace->events[] = 'authority';

            return $environment->user($grant);
        });
        $denied = $native->request();
        self::assertSame(403, $denied->getStatusCode());
        $expected = ['authority', 'report', 'response'];
        if ($form === 'constructor-middleware') {
            array_unshift($expected, 'construct');
        }

        self::assertSame($expected, $native->trace->events);
        $native->trace->events = [];
        $grant = true;
        $request = NativeApplication::incoming('/protected/123?name=valid');
        $admitted = $native->request($request);
        self::assertSame(204, $admitted->getStatusCode());
        self::assertSame('preserved', $admitted->headers->get('X-Lifecycle'));
        self::assertSame('authority', $native->trace->snapshot()[0]);
        self::assertContains('binding', $native->trace->events);
        self::assertContains('validator', $native->trace->events);
        self::assertContains('action', $native->trace->events);
        if (in_array($form, ['method-string', 'method-array', 'invokable'], true)) {
            self::assertSame([
                'authority', 'binding', 'validator', 'construct', 'form-authorize', 'form-rules',
                'provider', 'action', 'bus', 'response'
            ], $native->trace->events);
        }

        self::assertInstanceOf(SecurityContext::class, $request->attributes->get(SecurityContext::class));
        self::assertSame(2, $environment->resolutions);
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    public function test_native_controller_middleware_discovery_alone_can_select_permission_admission(): void
    {
        foreach ([ControllerWithStaticMiddleware::class, ControllerWithConstructorMiddleware::class] as $controller) {
            $native = new NativeApplication();
            $environment = new AdmissionEnvironment();
            $route = $native->route(action: $controller);
            $route->setAction(array_replace($route->getAction(), [
                'middleware' => [SubstituteBindings::class, RecordingValidationMiddleware::class]
            ]));
            $native->protect(
                [[$route, $this->protected()]],
                static fn(): RequestAuthority => $environment->user(false)
            );
            self::assertSame(403, $native->request()->getStatusCode());
            $expected = ['report', 'response'];
            if ($controller === ControllerWithConstructorMiddleware::class) {
                array_unshift($expected, 'construct');
            }

            self::assertSame($expected, $native->trace->events);
            self::assertSame(1, $environment->resolutions);
        }
    }

    public function test_form_request_validation_and_protected_work_remain_after_admission(): void
    {
        $native = new NativeApplication();
        $environment = new AdmissionEnvironment();
        $route = $native->route(action: ControllerWithFormRequest::class);
        $native->protect([[$route, $this->protected()]], static fn(): RequestAuthority => $environment->user());
        $invalid = $native->request();
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame([
            'binding', 'validator', 'construct', 'form-authorize', 'form-rules', 'report', 'response'
        ], $native->trace->events);
        self::assertNotContains('provider', $native->trace->events);
        self::assertNotContains('bus', $native->trace->events);
        self::assertStringNotContainsString('name', (string) $invalid->getContent());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'missing' => [null];
        yield 'raw-name' => ['AGENT_PROFILE_READ'];
        yield 'ambiguous' => [[new PermissionDeclaration(AdmissionMode::PUBLIC), new stdClass()]];
        yield 'foreign' => [new stdClass()];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_broken_declarations_deny_in_native_pipeline_before_authority_and_validation(mixed $value): void
    {
        $native = new NativeApplication();
        $calls = 0;
        $route = $native->route();
        $native->protect([[$route, $value]], static function () use (&$calls): ?RequestAuthority {
            ++$calls;

            return null;
        });
        self::assertSame(500, $native->request()->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame(['report', 'response'], $native->trace->events);
    }

    public function test_duplicate_route_bindings_are_configuration_failure_not_override(): void
    {
        $native = new NativeApplication();
        $route = $native->route();
        $this->expectException(LogicException::class);
        $native->protect([
            [$route, $this->protected()], [$route, new PermissionDeclaration(AdmissionMode::PUBLIC)]
        ], static fn(): ?RequestAuthority => null);
    }

    public function test_missing_binding_and_foreign_route_cannot_inherit_a_shared_callback_declaration(): void
    {
        $native = new NativeApplication();
        $first = $native->route();
        $second = $native->route('/other/{id}', $first->getAction('uses'));
        $calls = 0;
        $native->protect([[$first, new PermissionDeclaration(AdmissionMode::PUBLIC)]], static function () use (
            &$calls
        ): ?RequestAuthority {
            ++$calls;

            return null;
        });
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame(500, $native->request(NativeApplication::incoming('/other/123'))->getStatusCode());
        self::assertSame(0, $calls);
        self::assertNotSame($first, $second);
    }

    public function test_request_copies_headers_arguments_and_framework_user_resolver_cannot_grant(): void
    {
        $native = new NativeApplication();
        $environment = new AdmissionEnvironment();
        $route = $native->route();
        $native->protect([[$route, $this->protected()]], static fn(): RequestAuthority => $environment->user(false));
        $request = NativeApplication::incoming('/protected/AGENT_PROFILE_READ');
        $request->attributes->set(PermissionDeclaration::class, new PermissionDeclaration(AdmissionMode::PUBLIC));
        $request->attributes->set(SecurityContext::class, $environment->user()->resolve());
        $request->headers->set('X-Permissions', 'AGENT_PROFILE_READ');
        $request->setUserResolver(static fn(): object => (object) ['role' => 'ROLE_ADMINISTRATOR']);
        self::assertSame(403, $native->request($request)->getStatusCode());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        self::assertSame(['report', 'response'], $native->trace->events);
    }

    public function test_changed_action_and_forged_native_route_are_not_trusted(): void
    {
        $native = new NativeApplication();
        $route = $native->route();
        $public = $native->route('/public/{id}');
        $native->protect([
            [$route, $this->protected()], [$public, new PermissionDeclaration(AdmissionMode::PUBLIC)]
        ], static fn(): ?RequestAuthority => null);
        $native->app->make('events')->listen(RouteMatched::class, static function (RouteMatched $event) use (
            $public
        ): void {
            $event->request->setRouteResolver(static fn(): Route => $public);
        });
        self::assertSame(500, $native->request()->getStatusCode());
        self::assertSame(['report', 'response'], $native->trace->events);

        $changed = new NativeApplication();
        $route = $changed->route();
        $changed->protect([[$route, $this->protected()]], static fn(): ?RequestAuthority => null);
        $route->setAction(array_replace($route->getAction(), [
            'uses' => static fn(): Response => new Response('bypass')
        ]));
        self::assertSame(500, $changed->request()->getStatusCode());
        self::assertSame(['report', 'response'], $changed->trace->events);
    }

    public function test_unrouted_direct_middleware_and_missing_action_fail_closed(): void
    {
        $native = new NativeApplication();
        $route = new Route('GET', '/broken', ['uses' => static fn(): Response => new Response('bypass')]);
        $route->setAction(['uses' => null]);

        $middleware = $native->protect([[$route, $this->protected()]], static fn(): ?RequestAuthority => null);
        try {
            $middleware->handle(NativeApplication::incoming(), static fn(): Response => new Response('bypass'));
            self::fail('Unrouted middleware must reject.');
        } catch (LogicException) {
            self::assertSame([], $native->trace->events);
        }

        $native->router->getRoutes()->add($route);
        $route->middleware(PermissionMiddleware::class);
        self::assertSame(500, $native->request(NativeApplication::incoming('/broken'))->getStatusCode());
    }

    public function test_deliberate_public_and_authenticated_modes_are_distinct_from_missing_declarations(): void
    {
        $native = new NativeApplication($this->challenge());
        $environment = new AdmissionEnvironment();
        $public = $native->route('/public/{id}');
        $authenticated = $native->route();
        $calls = 0;
        $native->protect([
            [$public, new PermissionDeclaration(AdmissionMode::PUBLIC)],
            [$authenticated, new PermissionDeclaration(AdmissionMode::AUTHENTICATED)]
        ], static function () use ($environment, $native, &$calls): ?RequestAuthority {
            ++$calls;

            return $native->trace->authenticationPresent ? $environment->user(false) : null;
        });
        $request = NativeApplication::incoming('/public/123');
        $request->attributes->set(SecurityContext::class, $environment->user()->resolve());
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        self::assertSame(0, $calls);
        self::assertSame(401, $native->request()->getStatusCode());
        $native->trace->authenticationPresent = true;
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame(2, $calls);
    }

    public function test_reused_request_container_and_exception_paths_always_select_fresh_user_authority(): void
    {
        $native = new NativeApplication($this->challenge());
        $environment = new AdmissionEnvironment();
        $valid = true;
        $fault = null;
        $route = $native->route();
        $native->protect([[$route, $this->protected()]], static function () use (
            $environment,
            &$valid,
            &$fault
        ): RequestAuthority {
            return $environment->user(valid: $valid, fault: $fault);
        });
        $request = NativeApplication::incoming();
        self::assertSame(204, $native->request($request)->getStatusCode());
        $first = $request->attributes->get(SecurityContext::class);
        $valid = false;
        self::assertSame(401, $native->request($request)->getStatusCode());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        $valid = true;
        $fault = new RuntimeException('PRIVATE STORAGE', 401, new AuthenticationRequired('PRIVATE CAUSE'));
        self::assertSame(500, $native->request($request)->getStatusCode());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        $fault = null;
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertNotSame($first, $request->attributes->get(SecurityContext::class));
        self::assertSame(4, $environment->resolutions);
        self::assertCount(2, $native->trace->contexts);
        self::assertCount(2, $native->trace->reports);
    }

    public function test_provider_selection_and_return_type_faults_are_unknown_server_failures(): void
    {
        foreach ([new RuntimeException('PRIVATE CONFIG', 403), 'copied-principal'] as $result) {
            $native = new NativeApplication();
            $route = $native->route();
            $native->protect([[$route, $this->protected()]], static function () use ($result): mixed {
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            });
            $response = $native->request();
            self::assertSame(500, $response->getStatusCode());
            self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
            self::assertSame(['report', 'response'], $native->trace->events);
        }
    }

    public function test_nested_native_request_gets_own_selection_and_does_not_replace_parent_snapshot(): void
    {
        $native = new NativeApplication($this->challenge());
        $environment = new AdmissionEnvironment();
        $outer = $native->route(action: static function (Request $request) use ($native): Response {
            $parent = $request->attributes->get(SecurityContext::class);
            $nested = NativeApplication::incoming('/nested/123');
            $nested->attributes->set(SecurityContext::class, $parent);

            $denied = $native->request($nested);
            self::assertSame(401, $denied->getStatusCode());
            self::assertFalse($nested->attributes->has(SecurityContext::class));
            self::assertSame($parent, $request->attributes->get(SecurityContext::class));

            return new Response('', 204);
        });
        $inner = $native->route('/nested/{id}');
        $native->protect(
            [[$outer, $this->protected()], [$inner, $this->protected()]],
            static fn(Request $request): ?RequestAuthority =>
                $request->getPathInfo() === '/protected/123' ? $environment->user() : null
        );
        self::assertSame(204, $native->request(NativeApplication::incoming())->getStatusCode());
        self::assertSame(204, $native->request(NativeApplication::incoming('/protected/123?again=1'))->getStatusCode());
        self::assertSame(2, $environment->resolutions);
        self::assertCount(2, $native->trace->reports);
    }

    public function test_real_agent_direct_permissions_nonce_snapshot_fault_and_replay_are_not_user_fallback(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $native = new NativeApplication($this->challenge());
        $route = $native->route();
        $broken = false;
        $replay = false;
        $native->protect([[$route, $this->protected()]], static function (Request $request) use (
            $authority,
            &$broken,
            &$replay
        ): RequestAuthority {
            return $authority->create($request, $broken, $replay);
        });
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame(1, $environment->nonces->consumptionCalls());
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame(2, $environment->nonces->consumptionCalls());
        $replay = true;
        self::assertSame(401, $native->request()->getStatusCode());
        self::assertSame(3, $environment->nonces->consumptionCalls());
        $replay = false;
        $broken = true;
        self::assertSame(500, $native->request()->getStatusCode());
        $broken = false;
        $agent = $environment->storage->agentRepository->all()[0];
        $read = $environment->permissionRepository->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
        self::assertNotNull($read);
        $environment->storage->agentRepository->restoreSnapshot(
            [$agent->revokePermission($read->getId(), $environment->now())]
        );
        self::assertSame(403, $native->request()->getStatusCode());
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    public function test_opaque_agent_storage_fault_stays_500_without_guard_fallback_or_nonce_replay(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $repository = $this->createMock(AgentRepository::class);
        $repository->expects(self::once())->method('getByCredentialId')->willThrowException(
            new RuntimeException('PRIVATE STORAGE', 401, new AuthenticationRequired('PRIVATE CAUSE'))
        );
        $native = new NativeApplication($this->challenge());
        $route = $native->route();
        $native->protect([[$route, $this->protected()]], static function (Request $request) use (
            $environment,
            $authority,
            $repository
        ): RequestAuthority {
            $authority->create($request);
            $provider = new CurrentAgentPrincipalProvider(
                $repository,
                $environment->permissionRepository,
                new FixedHmacSharedSecretDecipher('auth-envelope:'),
                new FixedHmacSignedAgentRequestVerifier($environment->request, 'original-test-secret'),
                new FixedClock($environment->now()),
                $environment->nonces,
                $environment->storage->transaction
            );

            return RequestAuthority::agent($provider, $environment->request, $environment->correlationId);
        });
        $request = NativeApplication::incoming();
        $request->setUserResolver(static fn(): object => (object) ['role' => 'ROLE_ADMINISTRATOR']);

        $response = $native->request($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertSame(['report', 'response'], $native->trace->events);
        self::assertSame(1, $native->trace->deliveries);
        self::assertSame(0, $environment->nonces->consumptionCalls());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function test_agent_native_post_target_and_multiple_permissions_share_one_authenticated_snapshot(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $native = new NativeApplication();
        $route = $native->route(method: 'POST');

        $declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ'), PermissionName::fromString('AGENT_PROFILE_UPDATE')
        ]);
        $native->protect([[$route, $declaration]], $authority->create(...));
        $request = Request::create(
            'https://example.test:8443/protected/456?source=native',
            'POST',
            server: ['HTTP_ACCEPT' => 'application/json'],
            content: 'native-body'
        );
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertSame('POST', $environment->request->getMethod());
        self::assertSame('example.test:8443', $environment->request->getAuthority());
        self::assertSame('/protected/456', $environment->request->getPath());
        self::assertSame('source=native', $environment->request->getNormalizedQuery());
        self::assertSame('native-body', $environment->request->getBody());
        self::assertSame(hash('sha256', 'native-body'), $environment->request->getBodyDigest());
        $context = $request->attributes->get(SecurityContext::class);
        self::assertInstanceOf(SecurityContext::class, $context);
        self::assertTrue($context->hasPermission(PermissionName::fromString('AGENT_PROFILE_READ')));
        self::assertTrue($context->hasPermission(PermissionName::fromString('AGENT_PROFILE_UPDATE')));
        self::assertSame(1, $environment->nonces->consumptionCalls());
    }

    public function test_nested_agent_request_needs_own_nonce_without_automatic_parent_reauthentication(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $native = new NativeApplication($this->challenge());
        $replay = false;
        $outer = $native->route(action: static function (Request $parent) use ($native, &$replay): Response {
            $original = $parent->attributes->get(SecurityContext::class);
            $replay = true;
            $nested = NativeApplication::incoming('/nested/123');
            $nested->attributes->set(SecurityContext::class, $original);
            self::assertSame(401, $native->request($nested)->getStatusCode());
            self::assertFalse($nested->attributes->has(SecurityContext::class));
            $replay = false;
            $fresh = NativeApplication::incoming('/nested/456');
            self::assertSame(204, $native->request($fresh)->getStatusCode());
            self::assertNotSame($original, $fresh->attributes->get(SecurityContext::class));
            self::assertSame($original, $parent->attributes->get(SecurityContext::class));

            return new Response('', 204);
        });
        $inner = $native->route('/nested/{id}');
        $native->protect(
            [[$outer, $this->protected()], [$inner, $this->protected()]],
            static function (Request $request) use ($authority, &$replay): RequestAuthority {
                return $authority->create($request, replay: $replay);
            }
        );
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame(3, $environment->nonces->consumptionCalls());
        self::assertCount(1, $native->trace->reports);
    }

    private function protected(): PermissionDeclaration
    {
        return new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('AGENT_PROFILE_READ')]);
    }

    private function challenge(): SafeHttpProjector
    {
        return new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if ($failure instanceof AuthenticationRequired) {
                    return new SafeHttpPresentation(
                        metadata: new SafeHttpMetadata(challenge: 'Signature realm="example"')
                    );
                }

                return null;
            }
        };
    }
}
