<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureUnavailable;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Class FeatureMiddleware
 *
 * Binds effective requirements to the trusted native selected action before protected work.
 */
final readonly class FeatureMiddleware implements MiddlewareInterface
{
    /**
     * Constructs FeatureMiddleware
     *
     * @phpstan-param Closure(ServerRequestInterface): SelectedAction $selectedAction
     */
    public function __construct(
        private FeatureRequirements $requirements,
        private Closure $selectedAction,
        private RequestAuthentication $authentication,
        private FeatureAdmission $admission
    ) {
    }

    /**
     * Handles Feature restriction and translates only ordinary unavailability outward
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $references = $this->requirements->getReferences(($this->selectedAction)($request));

        return $this->authentication->admit(
            $request,
            $handler,
            function (?RequestAuthority $authority) use ($references): ?SecurityContext {
                $context = $authority?->resolve();
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
