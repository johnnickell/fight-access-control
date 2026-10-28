<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\RequestAuthority;
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
    /**
     * Constructs PermissionMiddleware
     *
     * @phpstan-param Closure(ServerRequestInterface): ?RequestAuthority $authority
     */
    public function __construct(
        private ?PermissionDeclaration $declaration,
        private Closure $authority,
        private PermissionAdmission $admission = new PermissionAdmission()
    ) {
    }

    /**
     * Handles admission using only the declaration bound by trusted native route composition
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authority = null;
        if ($this->declaration !== null && $this->declaration->mode !== AdmissionMode::PUBLIC) {
            $authority = ($this->authority)($request);
        }

        $context = $this->admission->admit($this->declaration, $authority);
        $request = $request->withoutAttribute(SecurityContext::class);
        if ($context !== null) {
            $request = $request->withAttribute(SecurityContext::class, $context);
        }

        return $handler->handle($request);
    }
}
