<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

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
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[CoversClass(FeatureMiddleware::class)]
#[CoversClass(PermissionMiddleware::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(SelectedRouteAction::class)]
final class IsolatedFeatureMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        foreach (['route', 'foreign', 'unbound', 'changed', 'unsupported', 'requirements', 'mismatch'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('faults')]
    public function test_invalid_target_is_not_absent_feature_metadata(string $fault): void
    {
        $action = static fn(): Response => new Response();
        $selected = $action;
        $route = $this->createStub(Route::class);
        $route->method('getAction')->willReturnCallback(static function () use (&$selected): mixed {
            return $selected;
        });
        $router = $this->createStub(Router::class);
        $router->method('getCurrentRoute')->willReturn($fault === 'foreign' ? $this->createStub(Route::class) : $route);
        $requirements = match ($fault) {
            'requirements' => new stdClass(),
            'mismatch' => new FeatureRequirements(SelectedAction::closure(static fn(): null => null)),
            default => null
        };
        if ($fault === 'unsupported') {
            $selected = 'container-alias@handle';
        }

        $gate = new FeatureMiddleware(
            $router,
            $fault === 'unbound' ? [] : [[$route, $requirements]],
            new RequestAuthentication(static function (): never {
                self::fail('Invalid targets must not authenticate.');
            }),
            new FeatureEnvironment()->admission
        );
        if ($fault === 'changed') {
            $selected = static fn(): null => null;
        }

        $request = $this->request($fault === 'route' ? null : $route);
        $request->attributes->set(SecurityContext::class, 'forged');
        try {
            $gate->handle($request, static function (): never {
                self::fail('Invalid targets must not dispatch.');
            });
            self::fail('Expected invalid target.');
        } catch (LogicException) {
            self::assertFalse($request->attributes->has(SecurityContext::class));
        }
    }

    public function test_duplicate_route_binding_is_not_last_writer_wins(): void
    {
        $route = $this->createStub(Route::class);
        $this->expectException(LogicException::class);
        new FeatureMiddleware(
            $this->createStub(Router::class),
            [[$route, null], [$route, null]],
            new RequestAuthentication(static fn(): null => null),
            new FeatureEnvironment()->admission
        );
    }

    /** @return iterable<string, array{string}> */
    public static function authorities(): iterable
    {
        foreach (['anonymous', 'user', 'select-refusal', 'select-fault', 'resolve-refusal', 'resolve-fault'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('authorities')]
    public function test_authority_selection_resolution_and_finally_lifetime(string $case): void
    {
        $environment = new FeatureEnvironment();
        $environment->seed(FeatureStatus::ON);

        $users = new AdmissionEnvironment();
        $calls = 0;
        $authenticate = new RequestAuthentication(static function () use ($case, $users, &$calls): ?RequestAuthority {
            ++$calls;

            return match ($case) {
                'select-refusal' => throw new AuthenticationRequired('PRIVATE'),
                'select-fault' => throw new RuntimeException('PRIVATE', 404),
                'resolve-refusal' => $users->user(valid: false),
                'resolve-fault' => $users->user(fault: new RuntimeException('PRIVATE', 401)),
                'user' => $users->user(),
                default => null
            };
        });
        [$router, $route] = $this->target();
        $gate = new FeatureMiddleware(
            $router,
            [[$route, $this->requirements($route)]],
            $authenticate,
            $environment->admission
        );
        $request = $this->request($route);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $response = $gate->handle($request, static function (Request $request) use ($case): Response {
                    self::assertSame($case === 'user', $request->attributes->has(SecurityContext::class));

                    return new Response('', 204);
                });
                self::assertSame(204, $response->getStatusCode());
                self::assertContains($case, ['anonymous', 'user']);
            } catch (Throwable $failure) {
                self::assertInstanceOf(
                    str_contains($case, 'refusal') ? AuthenticationRequired::class : LogicException::class,
                    $failure
                );
            }
        }

        self::assertSame(2, $calls);
    }

    public function test_cooperating_public_gate_preserves_trusted_context_and_cleanup_after_downstream_fault(): void
    {
        [$router, $route] = $this->target();
        $features = new FeatureEnvironment();
        $features->seed(FeatureStatus::ON);

        $users = new AdmissionEnvironment();
        $calls = 0;
        $authentication = new RequestAuthentication(static function () use ($users, &$calls): RequestAuthority {
            ++$calls;

            return $users->user();
        });
        $feature = new FeatureMiddleware(
            $router,
            [[$route, $this->requirements($route)]],
            $authentication,
            $features->admission
        );
        $permission = new PermissionMiddleware(
            $router,
            [[$route, new PermissionDeclaration(AdmissionMode::PUBLIC)]],
            $authentication
        );
        $request = $this->request($route);
        $failure = new RuntimeException('PRIVATE');
        try {
            $feature->handle($request, static fn(Request $request): Response => $permission->handle(
                $request,
                static function (Request $request) use ($failure): never {
                    self::assertInstanceOf(SecurityContext::class, $request->attributes->get(SecurityContext::class));
                    throw $failure;
                }
            ));
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $permission->handle($request, static function (Request $request): Response {
            self::assertFalse($request->attributes->has(SecurityContext::class));

            return new Response();
        });
        self::assertSame(1, $calls);
    }

    public function test_empty_requirements_skip_authentication_but_off_is_only_ordinary_not_found(): void
    {
        [$router, $route] = $this->target();
        $environment = new FeatureEnvironment();
        $gate = new FeatureMiddleware($router, [[$route, null]], new RequestAuthentication(static function (): never {
            self::fail('No Feature restriction must not authenticate.');
        }), $environment->admission);
        $response = $gate->handle($this->request($route), static fn(): Response => new Response('', 204));
        self::assertSame(204, $response->getStatusCode());
        $gate = new FeatureMiddleware(
            $router,
            [[$route, $this->requirements($route)]],
            new RequestAuthentication(static fn(): null => null),
            $environment->admission
        );
        $this->expectException(ResourceNotFound::class);
        $gate->handle($this->request($route), static function (): never {
            self::fail('OFF cannot dispatch.');
        });
    }

    public function test_class_metadata_is_read_without_controller_construction(): void
    {
        $selected = new SelectedRouteAction()->action(ControllerWithFormRequest::class.'@handle');
        $selected->assertMatches(SelectedAction::method(ControllerWithFormRequest::class, 'handle'));
        self::assertNotEmpty($selected->getDeclarations());
    }

    /** @return array{Router, Route} */
    private function target(): array
    {
        $route = $this->createStub(Route::class);
        $route->method('getAction')->willReturn(static fn(): Response => new Response());
        $router = $this->createStub(Router::class);
        $router->method('getCurrentRoute')->willReturn($route);

        return [$router, $route];
    }

    private function requirements(Route $route): FeatureRequirements
    {
        return new FeatureRequirements(SelectedAction::closure($route->getAction('uses')), 'rollout');
    }

    private function request(?Route $route): Request
    {
        $request = new Request();
        $request->setRouteResolver(static fn(): ?Route => $route);

        return $request;
    }
}
