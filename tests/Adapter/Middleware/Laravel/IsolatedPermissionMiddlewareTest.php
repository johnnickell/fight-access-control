<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(PermissionMiddleware::class)]
final class IsolatedPermissionMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        yield 'no current route' => ['missing'];
        yield 'not bound' => ['unbound'];
        yield 'changed action' => ['changed'];
        yield 'invalid declaration' => ['invalid'];
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_native_target_never_reaches_authority_or_next(string $problem): void
    {
        $route = $this->createStub(Route::class);
        $action = 'controller';
        $route->method('getAction')->willReturnCallback(static function () use (&$action): string {
            return $action;
        });
        $router = $this->createStub(Router::class);
        $router->method('getCurrentRoute')->willReturn($problem === 'missing' ? null : $route);
        $request = $this->request($route);
        $declaration = $problem === 'invalid' ? 'forged' : new PermissionDeclaration(AdmissionMode::AUTHENTICATED);
        $gate = new PermissionMiddleware(
            $router,
            $problem === 'unbound' ? [] : [[$route, $declaration]],
            static function (): never {
                self::fail('Invalid native binding must not authenticate.');
            }
        );
        if ($problem === 'changed') {
            // Native Route state is controlled here; the framework router/dispatcher is not executed.
            $action = 'changed-controller';
        }

        $this->expectException(LogicException::class);
        $gate->handle($request, static function (): never {
            self::fail('Invalid native binding must not dispatch.');
        });
    }

    public function test_admitted_context_replaces_copied_authority_and_public_path_does_not_authenticate(): void
    {
        $route = $this->createStub(Route::class);
        $route->method('getAction')->willReturn('controller');
        $router = $this->createStub(Router::class);
        $router->method('getCurrentRoute')->willReturn($route);
        $request = $this->request($route);
        $request->attributes->set(SecurityContext::class, 'forged');

        $environment = new AdmissionEnvironment();
        $gate = new PermissionMiddleware(
            $router,
            [[$route, new PermissionDeclaration(AdmissionMode::AUTHENTICATED)]],
            static fn(): RequestAuthority => $environment->user()
        );
        $response = new Response('', 204);
        self::assertSame($response, $gate->handle($request, static function (Request $next) use ($response): Response {
            self::assertInstanceOf(SecurityContext::class, $next->attributes->get(SecurityContext::class));

            return $response;
        }));
        $gate = new PermissionMiddleware(
            $router,
            [[$route, new PermissionDeclaration(AdmissionMode::PUBLIC)]],
            static function (): never {
                self::fail('Public admission must not select authority.');
            }
        );
        $gate->handle($request, static function (Request $next) use ($response): Response {
            self::assertFalse($next->attributes->has(SecurityContext::class));

            return $response;
        });
    }

    public function test_duplicate_target_declarations_are_rejected(): void
    {
        $route = $this->createStub(Route::class);
        $this->expectException(LogicException::class);

        new PermissionMiddleware(
            $this->createStub(Router::class),
            [[$route, null], [$route, null]],
            static fn(): null => null
        );
    }

    private function request(Route $route): Request
    {
        $request = new Request();
        $request->attributes = new ParameterBag();
        $request->setRouteResolver(static fn(): Route => $route);

        return $request;
    }
}
