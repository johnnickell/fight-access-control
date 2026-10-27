<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Throwable;
use Yiisoft\Di\Container;
use Yiisoft\Middleware\Dispatcher\MiddlewareFactory;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollector;

final class NativePipeline implements RequestHandlerInterface, SafeHttpDiagnostics
{
    public int $reports = 0;

    public int $validators = 0;

    /** @var list<?SecurityContext> */
    public array $contexts = [];

    /** @var list<string> */
    public array $reportedTypes = [];

    public ?Closure $reportHook = null;

    public ?Closure $beforeWork = null;

    public SafeJSendErrorMiddleware $errors;

    /** @var App<ContainerInterface|null> */
    private readonly App $slim;

    private readonly RouteCollection $yiiRoutes;

    public function __construct(
        private readonly string $framework,
        PermissionMiddleware $permission,
        string $form = 'method',
        ?PermissionMiddleware $otherPermission = null,
        ?Closure $protocol = null
    ) {
        $this->errors = $this->boundary();
        $this->slim = AppFactory::create(new ResponseFactory());
        $validator = function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
            ++$this->validators;
            $this->contexts[] = $request->getAttribute(SecurityContext::class);
            if ($this->beforeWork !== null) {
                ($this->beforeWork)($request);
            }

            return $handler->handle($request);
        };
        $slimAction = NativeController::class.':slim';
        if ($form === 'invokable') {
            $slimAction = NativeController::class;
        } elseif ($form === 'closure') {
            $slimAction = static fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface =>
                new NativeController()->slim($request, $response);
        }

        $this->slim->get('/protected/{id}', $slimAction)->add($validator)->add($permission);
        $this->slim->get('/other', static fn($request, $response): ResponseInterface => $response->withStatus(202));
        if ($otherPermission !== null) {
            $this->slim->get('/second/{id}', $slimAction)->add($validator)->add($otherPermission);
        }

        if ($protocol !== null) {
            $this->slim->get('/protocol', $protocol);
        }

        $this->slim->addRoutingMiddleware();
        $yiiAction = [NativeController::class, 'yii'];
        if ($form === 'closure') {
            $yiiAction = static fn(ServerRequestInterface $request): ResponseInterface =>
                new NativeController()->yii($request);
        }

        // Yii's supported definition is the bound PSR-15 process callable, not an invented shared route API.
        $collector = new RouteCollector();
        $collector->addRoute(
            Route::get('/protected/{id}')->middleware($permission->process(...), $validator)->action($yiiAction),
            Route::get('/other')->action(static fn(): ResponseInterface => new Response(202))
        );
        if ($otherPermission !== null) {
            $collector->addRoute(Route::get('/second/{id}')
                ->middleware($otherPermission->process(...), $validator)->action($yiiAction));
        }

        if ($protocol !== null) {
            $collector->addRoute(Route::get('/protocol')->action($protocol));
        }

        $this->yiiRoutes = new RouteCollection($collector);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->framework === 'slim') {
            return $this->slim->handle($request);
        }

        // CurrentRoute is single-assignment. A reused process/container must create a new request-owned router context.
        return new Router(
            new UrlMatcher($this->yiiRoutes),
            new ResponseFactory(),
            new MiddlewareFactory(new Container()),
            new CurrentRoute()
        )->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        });
    }

    public function request(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/protocol') {
            // Protocol owner selected outside ordinary HTTP/JSend error presentation.
            return $this->handle($request);
        }

        return $this->errors->process($request, $this);
    }

    public function report(Throwable $failure): void
    {
        ++$this->reports;
        // Model a redaction-aware owner: never forward the Throwable/message/cause/trace to a logger.
        $this->reportedTypes[] = $failure::class;
        ($this->reportHook ?? static function (): void {
        })();
    }

    public function boundary(
        ?SafeHttpProjector $projector = null,
        ?JSendResponseFactory $responses = null
    ): SafeJSendErrorMiddleware {
        $projector ??= new class implements SafeHttpProjector {
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

        return new SafeJSendErrorMiddleware(
            $responses ?? new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $this,
            $projector
        );
    }
}
