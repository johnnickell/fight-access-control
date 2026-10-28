<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Closure;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\RequestAuthority;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Adapter\Http\Symfony\Controller\SafeErrorController;
use Fight\Common\Adapter\Http\Symfony\EventSubscriber\SafeSymfonyExceptionSubscriber;
use Fight\Common\Adapter\Http\Symfony\EventSubscriber\SymfonyValidationSubscriber;
use Fight\Common\Application\Validation\ValidationService;
use ReflectionFunctionAbstract;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\ControllerAttributesListener;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener;
use Throwable;

final readonly class NativeKernel
{
    public HttpKernel $kernel;

    public EventDispatcher $events;

    public RouteCollection $routes;

    public NativeTrace $trace;

    public RequestStack $stack;

    /** @param Closure(Request): ?RequestAuthority $authority */
    public function __construct(
        Closure $authority,
        mixed $declaration,
        mixed $controller = NativeController::class.'::handle',
        ?SafeHttpProjector $projector = null
    ) {
        $this->trace = new NativeTrace();
        NativeController::$trace = $this->trace;
        NativeController::$work = null;
        $this->routes = new RouteCollection();
        $this->routes->add('protected', new Route('/protected/{id}', [
            '_controller'                => $controller,
            PermissionDeclaration::class => $declaration
        ], methods: ['GET', 'POST']));
        $this->routes->add('public', new Route('/public', [
            '_controller'                => static fn(): Response => new Response('', 202),
            PermissionDeclaration::class => new PermissionDeclaration(AdmissionMode::PUBLIC)
        ]));
        $this->events = new EventDispatcher();
        $this->stack = new RequestStack();
        $matcher = new UrlMatcher($this->routes, new RequestContext());
        $this->events->addSubscriber(new RouterListener($matcher, $this->stack));
        $this->events->addSubscriber(new PermissionSubscriber($matcher, $authority));

        $validation = new readonly class (new ValidationService(), $this->trace) extends SymfonyValidationSubscriber {
            public function __construct(ValidationService $service, private NativeTrace $trace)
            {
                parent::__construct($service);
            }

            public function validateControllerInput(ControllerEvent $event): void
            {
                $this->trace->events[] = 'validation';
                parent::validateControllerInput($event);
            }
        };
        $this->events->addSubscriber($validation);
        $checker = new readonly class ($this->trace) implements AuthorizationCheckerInterface {
            public function __construct(private NativeTrace $trace)
            {
            }

            public function isGranted(
                mixed $attribute,
                mixed $subject = null,
                ?AccessDecision $accessDecision = null
            ): bool {
                $this->trace->events[] = 'is-granted:'.$attribute.':'.($subject ?? 'none');

                return $this->trace->nativeGranted;
            }
        };
        // Symfony 8.1 dispatches IsGranted via its native controller-attribute listener.
        $this->events->addSubscriber(new ControllerAttributesListener([
            KernelEvents::CONTROLLER_ARGUMENTS => [IsGranted::class => true]
        ]));
        $this->events->addSubscriber(new IsGrantedAttributeListener($checker));

        $arguments = new readonly class ($this->trace) implements ArgumentResolverInterface {
            public function __construct(private NativeTrace $trace)
            {
            }

            /** @return list<mixed> */
            public function getArguments(
                Request $request,
                callable $controller,
                ?ReflectionFunctionAbstract $reflector = null
            ): array {
                $this->trace->events[] = 'arguments';

                return new ArgumentResolver()->getArguments($request, $controller, $reflector);
            }
        };
        $diagnostics = new readonly class ($this->trace) implements SafeHttpDiagnostics {
            public function __construct(private NativeTrace $trace)
            {
            }

            public function report(Throwable $failure): void
            {
                $this->trace->events[] = 'report';
                // The logger receives only an allowlisted type; never the private Throwable/context.
                $this->trace->reports[] = ['type' => $failure::class];
                if ($this->trace->sinkFails) {
                    throw new RuntimeException('PRIVATE SINK');
                }
            }
        };
        $safe = new SafeSymfonyExceptionSubscriber(new SafeErrorController(
            new SafeHttpErrorPolicy(),
            $diagnostics,
            $projector
        ));
        $this->events->addListener(KernelEvents::EXCEPTION, $safe->onKernelException(...), 64);
        $logger = new class ($this->trace) extends ErrorListener {
            public function __construct(private readonly NativeTrace $trace)
            {
                parent::__construct(null);
            }

            public function logKernelException(ExceptionEvent $event): void
            {
                $this->trace->events[] = 'raw-logger';
                parent::logKernelException($event);
            }
        };
        $this->events->addSubscriber($logger);
        $this->events->addListener(KernelEvents::EXCEPTION, function (ExceptionEvent $event): void {
            $this->trace->events[] = 'delegated-html';
            $event->setResponse(new Response('Consumer-owned HTML, not qualified as redacted.', 500));
        }, -64);
        $this->events->addListener(KernelEvents::RESPONSE, function (ResponseEvent $event): void {
            $this->trace->events[] = 'response';
            $event->getResponse()->headers->set('X-Lifecycle', 'preserved');
        });
        $this->kernel = new HttpKernel($this->events, new ControllerResolver(), $this->stack, $arguments);
    }

    public function request(Request $request, int $type = HttpKernel::MAIN_REQUEST): Response
    {
        return $this->kernel->handle($request, $type);
    }
}
