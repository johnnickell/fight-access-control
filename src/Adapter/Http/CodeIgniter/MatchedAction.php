<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\Router\Router;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use LogicException;
use ReflectionMethod;

/**
 * Class MatchedAction
 *
 * Shares native target association without constructing controllers or traversing declarations twice.
 */
final class MatchedAction
{
    /**
     * Returns the selected code from the current explicit native route
     */
    public static function fromRouter(Router $router): SelectedAction
    {
        if ($router->getMatchedRoute() === null) {
            throw new LogicException('An explicit native route is required.');
        }

        $controller = $router->controllerName();
        if ($controller instanceof Closure) {
            return SelectedAction::closure($controller);
        }

        if (!class_exists($controller) || method_exists($controller, '_remap')) {
            throw new LogicException('Unsupported native admission controller.');
        }

        $method = $router->methodName();
        if (new ReflectionMethod($controller, $method)->isStatic()) {
            throw new LogicException('Static native admission actions are not supported.');
        }

        return SelectedAction::method($controller, $method);
    }
}
