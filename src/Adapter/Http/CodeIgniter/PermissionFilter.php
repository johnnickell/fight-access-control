<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Router;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use LogicException;
use Throwable;

/**
 * Class PermissionFilter
 *
 * Delegate from an explicitly registered zero-argument native filter shell.
 */
final readonly class PermissionFilter implements FilterInterface
{
    /**
     * Constructs PermissionFilter
     *
     * @phpstan-param Closure(): Router $router
     * @phpstan-param Closure(IncomingRequest): ?RequestAuthority $authority
     */
    public function __construct(
        private Closure $router,
        private Closure $authority,
        private RequestContexts $contexts,
        private SafeErrorResponder $errors,
        private PermissionAdmission $admission = new PermissionAdmission()
    ) {
    }

    /**
     * Handles admission using only the current native matched route options
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $this->contexts->clear($request);
        try {
            if (!$request instanceof IncomingRequest || $arguments !== null && $arguments !== []) {
                throw new LogicException('Permission filter arguments are not supported.');
            }

            $router = ($this->router)();
            if ($router->getMatchedRoute() === null) {
                throw new LogicException('An explicit native route is required.');
            }

            // Native custom route options can contain objects despite CI4's scalar-only PHPDoc.
            /** @var array<string, mixed>|null $options */
            $options = $router->getMatchedRouteOptions();
            $declaration = $options[PermissionDeclaration::class] ?? null;
            if ($declaration !== null && !$declaration instanceof PermissionDeclaration) {
                throw new LogicException('Invalid target permission declaration.');
            }

            $authority = null;
            if ($declaration !== null && $declaration->mode !== AdmissionMode::PUBLIC) {
                $authority = ($this->authority)($request);
            }

            $context = $this->admission->admit($declaration, $authority);
            if ($context !== null) {
                $this->contexts->set($request, $context);
            }
        } catch (Throwable $throwable) {
            return $this->errors->respond($throwable);
        }

        return null;
    }

    /**
     * Handles the native after hook without further admission or authentication
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
