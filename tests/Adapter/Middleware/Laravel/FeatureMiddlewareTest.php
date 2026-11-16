<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Closure;
use Fight\AccessControl\Adapter\Middleware\Laravel\FeatureMiddleware;
use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Fight\AccessControl\Adapter\Middleware\Laravel\RequestAuthentication;
use Fight\AccessControl\Adapter\Middleware\Laravel\SelectedRouteAction;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber\NativeAgentAuthority;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[CoversClass(FeatureMiddleware::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(SelectedRouteAction::class)]
#[CoversClass(PermissionMiddleware::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeatureMiddlewareTest extends TestCase
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
        foreach (['closure', 'method-string', 'method-array', 'invokable', 'static', 'legacy'] as $form) {
            yield $form => [$form];
        }
    }

    #[DataProvider('forms')]
    public function test_feature_only_actual_native_forms_status_and_pre_validation_order(string $form): void
    {
        $features = new FeatureEnvironment();
        $users = new AdmissionEnvironment();
        $native = new NativeApplication($this->challenge());
        $action = match ($form) {
            'method-string' => ControllerWithFormRequest::class.'@handle',
            'method-array' => [ControllerWithFormRequest::class, 'handle'],
            'invokable' => ControllerWithFormRequest::class,
            'static' => FeatureStaticController::class,
            'legacy' => FeatureLegacyController::class,
            default => null
        };
        $route = $this->route($native, $action);
        $grant = true;
        $anonymous = false;
        $valid = true;
        $fault = null;
        $this->install($native, $features, [[$route, $this->requirements($route)]], static function () use (
            $native,
            $features,
            $users,
            &$grant,
            &$anonymous,
            &$valid,
            &$fault
        ): ?RequestAuthority {
            $native->trace->events[] = 'authority';

            return $anonymous ? null : $users->user($grant, $valid, $fault, $features->permissionId);
        });
        $request = NativeApplication::incoming('/protected/123?name=valid');
        $denied = $native->request($request);
        self::assertSame(404, $denied->getStatusCode());
        self::assertNotContains('binding', $native->trace->events);
        self::assertNotContains('validator', $native->trace->events);
        self::assertNotContains('form-rules', $native->trace->events);
        self::assertNotContains('action', $native->trace->events);
        self::assertSame($form === 'legacy', in_array('construct', $native->trace->events, true));
        self::assertSame($form === 'static', in_array('discovery', $native->trace->events, true));
        self::assertStringNotContainsString('rollout', (string) $denied->getContent());
        self::assertSame(1, $native->trace->deliveries);
        $features->seed(FeatureStatus::PREVIEW);
        $native->trace->events = [];
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertInstanceOf(SecurityContext::class, $request->attributes->get(SecurityContext::class));
        if (in_array($form, ['method-string', 'method-array', 'invokable'], true)) {
            self::assertSame([
                'authority', 'binding', 'validator', 'construct', 'form-authorize', 'form-rules',
                'provider', 'action', 'bus', 'response'
            ], $native->trace->events);
        }

        $grant = false;
        self::assertSame(404, $native->request($request)->getStatusCode());
        $anonymous = true;
        self::assertSame(404, $native->request($request)->getStatusCode());
        $features->seed(FeatureStatus::ON);
        $request->attributes->set(SecurityContext::class, 'forged');
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        $anonymous = false;
        $valid = false;
        self::assertSame(401, $native->request($request)->getStatusCode());
        $valid = true;
        $fault = new RuntimeException('PRIVATE PROVIDER', 404);
        $failure = $native->request($request);
        self::assertSame(500, $failure->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $failure->getContent());
        self::assertNotContains('raw-logger', $native->trace->events);
        self::assertSame(0, $features->features->writes);
        self::assertFalse($native->app->bound(PermissionMiddleware::class));
    }

    public function test_native_inherited_overridden_repeated_and_route_requirements_are_conjunctive(): void
    {
        $features = new FeatureEnvironment();
        $native = new NativeApplication();
        $route = $this->route($native, [FeatureChildController::class, 'handle']);
        $this->install($native, $features, [[$route, $this->requirements($route)]], static fn(): null => null);
        $names = ['parent', 'overridden', 'child', 'method', 'rollout'];
        foreach ($names as $name) {
            $features->seed(FeatureStatus::ON, $name);
        }

        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame($names, $features->features->lookups);
        foreach ($names as $name) {
            $features->seed(FeatureStatus::OFF, $name);
            $native->trace->events = [];
            self::assertSame(404, $native->request()->getStatusCode());
            self::assertNotContains('action', $native->trace->events);
            $features->seed(FeatureStatus::ON, $name);
        }
    }

    public function test_native_form_request_is_still_validated_when_feature_is_on(): void
    {
        $features = new FeatureEnvironment();
        $features->seed(FeatureStatus::ON);

        $native = new NativeApplication();
        $route = $this->route($native, ControllerWithFormRequest::class);
        $this->install($native, $features, [[$route, $this->requirements($route)]], static fn(): null => null);
        self::assertSame(422, $native->request()->getStatusCode());
        self::assertContains('form-rules', $native->trace->events);
        self::assertNotContains('provider', $native->trace->events);
        self::assertNotContains('action', $native->trace->events);
    }

    public function test_missing_broken_and_mismatched_binding_are_faults_at_every_status(): void
    {
        $features = new FeatureEnvironment();
        $native = new NativeApplication();
        $route = $this->route($native);
        $this->install($native, $features, [[$route, $this->requirements($route)]], static fn(): null => null);
        $features->features->records = [];
        self::assertSame(500, $native->request()->getStatusCode());
        foreach (FeatureStatus::cases() as $status) {
            $features->seed($status, permissionId: PermissionId::generate());
            $response = $native->request();
            self::assertSame(500, $response->getStatusCode());
            self::assertStringNotContainsString('rollout', (string) $response->getContent());
        }

        self::assertNotContains('action', $native->trace->events);
        self::assertNotContains('validator', $native->trace->events);
    }

    public function test_route_identity_action_mutation_forged_requirements_and_metadata_faults_fail_closed(): void
    {
        $features = new FeatureEnvironment();
        $native = new NativeApplication();
        $route = $this->route($native);
        $other = $this->route($native, $route->getAction('uses'), '/other/{id}');
        $this->install($native, $features, [[$route, $this->requirements($route)]], static fn(): null => null);
        $request = NativeApplication::incoming();
        $request->attributes->set(FeatureRequirements::class, new FeatureRequirements(
            SelectedAction::closure($route->getAction('uses'))
        ));
        self::assertSame(404, $native->request($request)->getStatusCode());
        self::assertSame(500, $native->request(NativeApplication::incoming('/other/123'))->getStatusCode());
        $native->app->make('events')->listen(
            RouteMatched::class,
            static function (RouteMatched $event) use ($other): void {
                $event->request->setRouteResolver(static fn(): Route => $other);
            }
        );
        self::assertSame(500, $native->request()->getStatusCode());
        $native->app->make('events')->forget(RouteMatched::class);
        $route->setAction(array_replace($route->getAction(), [
            'uses' => static fn(): Response => new Response('bypass')
        ]));
        self::assertSame(500, $native->request()->getStatusCode());
        self::assertNotContains('action', $native->trace->events);
        $this->install($native, $features, [[$route, new stdClass()]], static fn(): null => null);
        self::assertSame(500, $native->request()->getStatusCode());
    }

    public function test_nested_error_and_reused_request_lifetimes_never_inherit_identity(): void
    {
        $features = new FeatureEnvironment();
        $features->seed(FeatureStatus::PREVIEW);

        $users = new AdmissionEnvironment();
        $native = new NativeApplication();
        $parent = NativeApplication::incoming();
        $parent->headers->set('X-Own-Credential', 'fixture');

        $child = NativeApplication::incoming('/child/123');
        $parentRoute = $this->route($native, function (
            Request $request,
            string $id
        ) use (
            $native,
            $parent,
            $child
        ): Response {
            $context = $parent->attributes->get(SecurityContext::class);
            $child->attributes->set(SecurityContext::class, $context);
            self::assertSame(404, $native->request($child)->getStatusCode());
            self::assertSame($context, $parent->attributes->get(SecurityContext::class));

            return new Response('', 204);
        });
        $childRoute = $this->route($native, path: '/child/{id}');
        $this->install($native, $features, [
            [$parentRoute, $this->requirements($parentRoute)], [$childRoute, $this->requirements($childRoute)]
        ], static function (Request $request) use ($users, $features): ?RequestAuthority {
            if ($request->headers->has('X-Own-Credential')) {
                return $users->user(permissionId: $features->permissionId);
            }

            return null;
        });
        self::assertSame(204, $native->request($parent)->getStatusCode());
        self::assertSame(1, $users->resolutions);
        self::assertFalse($child->attributes->has(SecurityContext::class));
        $child->headers->set('X-Own-Credential', 'child-fixture');
        $native->trace->failure = new RuntimeException('PRIVATE ACTION');
        self::assertSame(500, $native->request($child)->getStatusCode());
        $native->trace->failure = null;
        self::assertSame(204, $native->request($child)->getStatusCode());
        $child->headers->remove('X-Own-Credential');
        self::assertSame(404, $native->request($child)->getStatusCode());
        self::assertSame(3, $users->resolutions);
        self::assertFalse($child->attributes->has(SecurityContext::class));
    }

    /** @return iterable<string, array{bool}> */
    public static function orders(): iterable
    {
        yield 'feature-first' => [false];
        yield 'permission-first' => [true];
    }

    #[DataProvider('orders')]
    public function test_cooperating_gates_share_agent_authentication_without_grant_from_on(bool $permissionFirst): void
    {
        $features = new FeatureEnvironment();
        $features->seed(FeatureStatus::PREVIEW);

        $agent = new ProfileToolEnvironment();
        $permission = $agent->permissionRepository->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
        $features->permissions->add($permission);
        $features->seed(FeatureStatus::PREVIEW, permissionId: $permission->getId());

        $native = new NativeApplication($this->challenge());
        $route = $this->route($native);
        $route->middleware(PermissionMiddleware::class);
        if ($permissionFirst) {
            $native->kernel->prependToMiddlewarePriority(PermissionMiddleware::class);
        }

        $factory = new NativeAgentAuthority($agent);
        $calls = 0;
        $replay = false;
        $select = static function (Request $request) use ($factory, &$calls, &$replay): RequestAuthority {
            ++$calls;

            return $factory->create($request, replay: $replay);
        };
        $authentication = $this->install($native, $features, [[$route, $this->requirements($route)]], $select);
        $native->app->instance(PermissionMiddleware::class, new PermissionMiddleware($native->router, [
            [$route, new PermissionDeclaration(AdmissionMode::PROTECTED, [
                PermissionName::fromString('AGENT_PROFILE_READ')
            ])]
        ], $authentication));
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertSame(1, $calls);
        self::assertSame(1, $agent->nonces->consumptionCalls());
        $features->seed(FeatureStatus::OFF, permissionId: $permission->getId());
        $native->trace->events = [];
        self::assertSame(404, $native->request()->getStatusCode());
        self::assertNotContains('action', $native->trace->events);
        self::assertSame(2, $agent->nonces->consumptionCalls());
        $replay = true;
        self::assertSame(401, $native->request()->getStatusCode());
        self::assertSame(3, $agent->nonces->consumptionCalls());
        $replay = false;
        $features->seed(FeatureStatus::ON, permissionId: $permission->getId());
        $native->app->instance(PermissionMiddleware::class, new PermissionMiddleware($native->router, [
            [$route, new PermissionDeclaration(AdmissionMode::PROTECTED, [
                PermissionName::fromString('AGENT_PROFILE_WRITE')
            ])]
        ], $authentication));
        $native->trace->events = [];
        self::assertSame(403, $native->request()->getStatusCode());
        self::assertNotContains('action', $native->trace->events);
    }

    private function route(NativeApplication $native, mixed $action = null, string $path = '/protected/{id}'): Route
    {
        return $native->route($path, $action, middleware: [
            FeatureMiddleware::class, SubstituteBindings::class, RecordingValidationMiddleware::class
        ]);
    }

    private function requirements(Route $route): FeatureRequirements
    {
        return new FeatureRequirements(new SelectedRouteAction()->action($route->getAction('uses')), 'rollout');
    }

    /**
     * @param list<array{Route, mixed}> $targets
     * @param Closure(Request): ?RequestAuthority $authority
     */
    private function install(
        NativeApplication $native,
        FeatureEnvironment $features,
        array $targets,
        Closure $authority
    ): RequestAuthentication {
        $authentication = new RequestAuthentication($authority);
        $native->app->instance(FeatureMiddleware::class, new FeatureMiddleware(
            $native->router,
            $targets,
            $authentication,
            $features->admission
        ));
        $native->router->bind('id', static function (string $id) use ($native): string {
            $native->trace->events[] = 'binding';

            return $id;
        });

        return $authentication;
    }

    private function challenge(): SafeHttpProjector
    {
        return new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                return $status === 401 ? new SafeHttpPresentation(
                    metadata: new SafeHttpMetadata(challenge: 'Signature realm="example"')
                ) : null;
            }
        };
    }
}
