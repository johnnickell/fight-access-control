<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Laravel;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureUnavailable;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

/**
 * Class FeatureMiddleware
 *
 * Restricts exact trusted serving routes independently of Permission middleware.
 */
final readonly class FeatureMiddleware
{
    /** @var WeakMap<Route, array{mixed, mixed}> */
    private WeakMap $targets;

    /**
     * Constructs FeatureMiddleware
     *
     * @phpstan-param list<array{Route, mixed}> $targets
     */
    public function __construct(
        private Router $router,
        array $targets,
        private RequestAuthentication $authentication,
        private FeatureAdmission $admission,
        private SelectedRouteAction $selectedRouteAction = new SelectedRouteAction()
    ) {
        $this->targets = new WeakMap();
        foreach ($targets as [$route, $requirements]) {
            if (isset($this->targets[$route])) {
                throw new LogicException('Ambiguous target Feature requirements.');
            }

            $this->targets[$route] = [$route->getAction('uses'), $requirements];
        }
    }

    /**
     * Handles effective Feature restrictions before protected binding and validation
     *
     * @phpstan-param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove(SecurityContext::class);
        try {
            $route = $request->route();
            if (
                !$route instanceof Route || $route !== $this->router->getCurrentRoute() ||
                !isset($this->targets[$route])
            ) {
                throw new LogicException('The selected Feature target does not match native routing.');
            }

            [$action, $requirements] = $this->targets[$route];
            if ($action !== $route->getAction('uses')) {
                throw new LogicException('The selected Feature action has changed.');
            }

            $selected = $this->selectedRouteAction->action($action);
            $requirements ??= new FeatureRequirements($selected);
            if (!$requirements instanceof FeatureRequirements) {
                throw new LogicException('Invalid target Feature requirements.');
            }

            $references = $requirements->getReferences($selected);
        } catch (Throwable $throwable) {
            throw new LogicException('Feature selected action resolution failed.', previous: $throwable);
        }

        return $this->authentication->admit(
            $request,
            $next,
            function (?RequestAuthority $authority) use ($references): ?SecurityContext {
                try {
                    $context = $authority?->resolve();
                } catch (AuthenticationRequired $failure) {
                    throw $failure;
                } catch (Throwable $failure) {
                    throw new LogicException('Feature authority resolution failed.', previous: $failure);
                }

                try {
                    return $this->admission->admit($references, $context);
                } catch (FeatureUnavailable $featureUnavailable) {
                    throw new ResourceNotFound('The target is unavailable.', previous: $featureUnavailable);
                }
            },
            $references->getNames() !== []
        );
    }
}
