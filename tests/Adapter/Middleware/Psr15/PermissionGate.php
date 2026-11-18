<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthentication;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionRequirements;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Psr\Http\Message\ServerRequestInterface;

final class PermissionGate
{
    /**
     * @param (Closure(ServerRequestInterface): ?RequestAuthority)|RequestAuthentication $authority
     * @return Closure(SelectedAction, Closure(ServerRequestInterface): SelectedAction): PermissionMiddleware
     */
    public static function bind(?PermissionDeclaration $route, Closure|RequestAuthentication $authority): Closure
    {
        return static fn(SelectedAction $action, Closure $selected): PermissionMiddleware => new PermissionMiddleware(
            new PermissionRequirements($action, $route),
            $selected,
            $authority
        );
    }
}
