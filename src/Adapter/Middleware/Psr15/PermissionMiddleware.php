<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionRequirements;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Class PermissionMiddleware
 *
 * Bind this instance to one native route before protected validators and dispatch.
 * The factory must create fresh request-owned providers, including for nested dispatch.
 */
final readonly class PermissionMiddleware implements MiddlewareInterface
{
    private RequestAuthentication $authentication;

    /**
     * Constructs PermissionMiddleware
     *
     * @phpstan-param Closure(ServerRequestInterface): SelectedAction $selectedAction
     * @phpstan-param (Closure(ServerRequestInterface): ?RequestAuthority)|RequestAuthentication $authority
     */
    public function __construct(
        private PermissionRequirements $requirements,
        private Closure $selectedAction,
        Closure|RequestAuthentication $authority,
        private PermissionAdmission $admission = new PermissionAdmission()
    ) {
        if ($authority instanceof Closure) {
            $authority = new RequestAuthentication($authority);
        }

        $this->authentication = $authority;
    }

    /**
     * Handles effective admission for the actual trusted native selected action
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $declaration = $this->requirements->getDeclaration(($this->selectedAction)($request));
        } catch (Throwable $throwable) {
            throw new LogicException('Permission selected action resolution failed.', previous: $throwable);
        }

        return $this->authentication->admit(
            $request,
            $handler,
            fn(?RequestAuthority $authority): ?SecurityContext =>
                $this->admission->admit($declaration, $authority),
            $declaration !== null && $declaration->mode !== AdmissionMode::PUBLIC
        );
    }
}
