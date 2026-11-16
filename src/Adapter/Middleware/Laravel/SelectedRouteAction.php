<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Laravel;

use Closure;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use LogicException;

/**
 * Class SelectedRouteAction
 *
 * Reads normalized Laravel action metadata without resolving a controller from the container.
 */
final readonly class SelectedRouteAction
{
    /**
     * Returns the exact selected code for supported native action forms
     */
    public function action(mixed $action): SelectedAction
    {
        if ($action instanceof Closure) {
            return SelectedAction::closure($action);
        }

        if (is_string($action) && substr_count($action, '@') === 1) {
            [$class, $method] = explode('@', $action, 2);
            if (class_exists($class)) {
                return SelectedAction::method($class, $method);
            }
        }

        throw new LogicException('Unsupported selected Laravel action.');
    }
}
