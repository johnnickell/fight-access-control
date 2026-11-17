<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Router;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureUnavailable;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use LogicException;
use Throwable;

/**
 * Class FeatureFilter
 *
 * Restricts the native matched action independently of Permission filter registration.
 */
final readonly class FeatureFilter implements FilterInterface
{
    /**
     * Constructs FeatureFilter
     *
     * @phpstan-param Closure(): Router $router
     */
    public function __construct(
        private Closure $router,
        private RequestAuthentication $authentication,
        private FeatureAdmission $admission,
        private SafeErrorResponder $errors
    ) {
    }

    /**
     * Handles effective Feature restrictions before native protected validation and action work
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        try {
            try {
                if (!$request instanceof IncomingRequest || $arguments !== null && $arguments !== []) {
                    throw new LogicException('Feature filter arguments are not supported.');
                }

                $router = ($this->router)();
                $this->authentication->begin($request, $router, self::class);
                $action = MatchedAction::fromRouter($router);
                // CI4 accepts object-valued custom options despite its scalar-only PHPDoc.
                /** @var array<string, mixed>|null $options */
                $options = $router->getMatchedRouteOptions();
                $requirements = $options[FeatureRequirements::class] ?? new FeatureRequirements($action);
                if (!$requirements instanceof FeatureRequirements) {
                    throw new LogicException('Invalid target Feature requirements.');
                }

                $references = $requirements->getReferences($action);
            } catch (Throwable $failure) {
                throw new LogicException('Feature selected action resolution failed.', previous: $failure);
            }

            $context = null;
            if ($references->getNames() !== []) {
                try {
                    $context = $this->authentication->authority($request)?->resolve();
                } catch (AuthenticationRequired $failure) {
                    throw $failure;
                } catch (Throwable $failure) {
                    throw new LogicException('Feature authority resolution failed.', previous: $failure);
                }
            }

            try {
                $context = $this->admission->admit($references, $context);
            } catch (FeatureUnavailable $unavailable) {
                throw new ResourceNotFound('The target is unavailable.', previous: $unavailable);
            }

            $this->authentication->publish($request, $context);
        } catch (Throwable $throwable) {
            if ($request instanceof IncomingRequest) {
                $this->authentication->clear($request);
            }

            return $this->errors->respond($throwable);
        }

        return null;
    }

    /**
     * Handles the native after hook without additional authentication or evaluation
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
