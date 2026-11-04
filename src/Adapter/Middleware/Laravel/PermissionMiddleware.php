<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Laravel;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use WeakMap;

/**
 * Class PermissionMiddleware
 *
 * Select explicitly as route middleware before bindings, validation and dispatch.
 * Bind after route registration; native route/action identity is immutable during serving.
 */
final readonly class PermissionMiddleware
{
    /** @var WeakMap<Route, array{mixed, mixed}> */
    private WeakMap $targets;

    /**
     * Constructs PermissionMiddleware
     *
     * @phpstan-param list<array{Route, mixed}> $targets
     * @phpstan-param Closure(Request): ?RequestAuthority $authority
     */
    public function __construct(
        private Router $router,
        array $targets,
        private Closure $authority,
        private PermissionAdmission $admission = new PermissionAdmission()
    ) {
        $this->targets = new WeakMap();
        foreach ($targets as [$route, $declaration]) {
            if (isset($this->targets[$route])) {
                throw new LogicException('Ambiguous target permission declaration.');
            }

            $this->targets[$route] = [$route->getAction('uses'), $declaration];
        }
    }

    /**
     * Handles native admission without trusting request attributes or a framework guard
     *
     * @phpstan-param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove(SecurityContext::class);
        $route = $request->route();
        if (
            !$route instanceof Route || $route !== $this->router->getCurrentRoute() ||
            !isset($this->targets[$route])
        ) {
            throw new LogicException('The selected permission target does not match native routing.');
        }

        [$action, $declaration] = $this->targets[$route];
        if ($action === null || $action !== $route->getAction('uses')) {
            throw new LogicException('The selected permission action has changed.');
        }

        if ($declaration !== null && !$declaration instanceof PermissionDeclaration) {
            throw new LogicException('Invalid target permission declaration.');
        }

        $authority = null;
        if ($declaration !== null && $declaration->mode !== AdmissionMode::PUBLIC) {
            $authority = ($this->authority)($request);
        }

        $context = $this->admission->admit($declaration, $authority);
        if ($context !== null) {
            $request->attributes->set(SecurityContext::class, $context);
        }

        return $next($request);
    }
}
