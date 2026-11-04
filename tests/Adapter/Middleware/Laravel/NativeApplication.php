<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Closure;
use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Adapter\Http\Laravel\SafeLaravelExceptionHandler;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Providers\FormRequestServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class NativeApplication
{
    public Application $app;

    public Kernel $kernel;

    public Router $router;

    public NativeTrace $trace;

    public function __construct(?SafeHttpProjector $projector = null)
    {
        // Load the real supplier helpers only after selecting Laravel in this isolated process.
        $framework = dirname((string) new ReflectionClass(Application::class)->getFileName(), 4);
        require_once $framework.'/src/Illuminate/Foundation/helpers.php';
        require_once $framework.'/src/Illuminate/Support/helpers.php';

        $this->trace = new NativeTrace();
        NativeController::$trace = $this->trace;
        $app = new Application(__DIR__);
        $this->app = $app;
        $app->instance('config', new Repository([
            'app'  => ['debug' => true, 'timezone' => 'UTC'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()]
        ]));
        $app->register(FilesystemServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $app->register(ValidationServiceProvider::class);
        $app->register(FormRequestServiceProvider::class);
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $app->instance(LoggerInterface::class, new class ($this->trace) extends AbstractLogger {
            public function __construct(private readonly NativeTrace $trace)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->trace->events[] = 'raw-logger';
            }
        });
        $app->instance(SafeHttpDiagnostics::class, new readonly class ($this->trace) implements SafeHttpDiagnostics {
            public function __construct(private NativeTrace $trace)
            {
            }

            public function report(Throwable $failure): void
            {
                $this->trace->events[] = 'report';
                $this->trace->reports[] = $failure::class;
                if ($this->trace->sinkFails) {
                    throw new RuntimeException('PRIVATE SINK');
                }

                ++$this->trace->deliveries;
            }
        });
        $app->instance(SafeHttpErrorPolicy::class, new SafeHttpErrorPolicy());
        if ($projector !== null) {
            $app->instance(SafeHttpProjector::class, $projector);
        }

        Facade::setFacadeApplication($app);
        $app->bootstrapWith([]);
        $app->boot();
        $app->singleton(ExceptionHandler::class, SafeLaravelExceptionHandler::class);
        /** @var SafeLaravelExceptionHandler $handler */
        $handler = $app->make(ExceptionHandler::class);
        $handler->reportable(function (Throwable $failure): void {
            $this->trace->events[] = 'raw-reportable';
        });
        $handler->renderable(function (Throwable $failure): Response {
            $this->trace->events[] = 'raw-renderable';

            return new Response('PRIVATE RENDERABLE');
        });
        $this->router = $app->make('router');
        $this->kernel = new Kernel($app, $this->router);
        $this->kernel->prependToMiddlewarePriority(PermissionMiddleware::class);
        $app->make('events')->listen(RequestHandled::class, function (RequestHandled $event): void {
            $this->trace->events[] = 'response';
            $event->response->headers->set('X-Lifecycle', 'preserved');
        });
    }

    public static function incoming(string $path = '/protected/123'): Request
    {
        return Request::create('https://example.test'.$path, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    public function route(string $path = '/protected/{id}', mixed $action = null, string $method = 'GET'): Route
    {
        $action ??= static function (Request $request, string $id): Response {
            NativeController::$trace->events[] = 'action';
            NativeController::$trace->contexts[] = $request->attributes->get(SecurityContext::class);
            if (NativeController::$trace->failure !== null) {
                throw NativeController::$trace->failure;
            }

            return new Response('', 204, ['X-Action' => $id]);
        };

        return $this->router->match([$method], $path, $action)
            ->middleware([PermissionMiddleware::class, SubstituteBindings::class, NativeValidator::class]);
    }

    /**
     * @param list<array{Route, mixed}> $targets
     * @param Closure(Request): mixed $authority
     */
    public function protect(array $targets, Closure $authority): PermissionMiddleware
    {
        $middleware = new PermissionMiddleware(
            $this->router,
            $targets,
            static fn(Request $request): ?RequestAuthority => $authority($request)
        );
        $this->app->instance(PermissionMiddleware::class, $middleware);
        $this->router->bind('id', function (string $id): string {
            $this->trace->events[] = 'binding';

            return $id;
        });

        return $middleware;
    }

    public function request(?Request $request = null): Response
    {
        return $this->kernel->handle($request ?? self::incoming());
    }
}
