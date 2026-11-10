<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
     * @phpstan-param (Closure(ServerRequestInterface): ?RequestAuthority)|RequestAuthentication $authority
     */
    public function __construct(
        private ?PermissionDeclaration $declaration,
        Closure|RequestAuthentication $authority,
        private PermissionAdmission $admission = new PermissionAdmission()
    ) {
        $authentication = $authority;
        if ($authority instanceof Closure) {
            $authentication = new RequestAuthentication($authority);
        }

        $this->authentication = $authentication;
    }

    /**
     * Handles admission using only the declaration bound by trusted native route composition
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->authentication->admit(
            $request,
            $handler,
            fn(?RequestAuthority $authority): ?SecurityContext =>
                $this->admission->admit($this->declaration, $authority),
            $this->declaration !== null && $this->declaration->mode !== AdmissionMode::PUBLIC
        );
    }
}
