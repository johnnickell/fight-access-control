<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\Symfony;

use Closure;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use LogicException;

/**
 * Class SelectedController
 *
 * Maps supported native controller forms to inward metadata without constructing controllers.
 */
final readonly class SelectedController
{
    /**
     * Returns the exact selected action or rejects unsupported native composition
     */
    public function action(mixed $controller): SelectedAction
    {
        if ($controller instanceof Closure) {
            return SelectedAction::closure($controller);
        }

        if (is_string($controller)) {
            $parts = explode('::', $controller);
            if (count($parts) === 1 && class_exists($controller)) {
                return SelectedAction::invokable($controller);
            }

            if (count($parts) === 2 && class_exists($parts[0])) {
                return SelectedAction::method($parts[0], $parts[1]);
            }
        }

        if (is_array($controller) && array_is_list($controller) && count($controller) === 2) {
            [$owner, $method] = $controller;
            if (is_object($owner) && is_string($method)) {
                return SelectedAction::method($owner::class, $method);
            }

            if (is_string($owner) && class_exists($owner) && is_string($method)) {
                return SelectedAction::method($owner, $method);
            }
        }

        if (is_object($controller) && !$controller instanceof Closure) {
            return SelectedAction::invokable($controller::class);
        }

        throw new LogicException('Unsupported selected Symfony controller.');
    }
}
