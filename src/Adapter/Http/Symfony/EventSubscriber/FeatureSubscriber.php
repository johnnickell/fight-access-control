<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Adapter\Http\Symfony\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\Symfony\SelectedController;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureUnavailable;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use LogicException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Throwable;

/**
 * Class FeatureSubscriber
 *
 * Restricts trusted matched actions after routing and before lazy controller construction.
 */
final readonly class FeatureSubscriber implements EventSubscriberInterface
{
    /**
     * Constructs FeatureSubscriber
     */
    public function __construct(
        private RequestMatcherInterface $targets,
        private RequestAuthentication $authentication,
        private FeatureAdmission $admission,
        private SelectedController $controllers = new SelectedController()
    ) {
    }

    /**
     * Returns admission and resolved-controller integrity checkpoints
     *
     * @return array{
     *     'kernel.request': array{'onKernelRequest', 16},
     *     'kernel.controller': array{'onKernelController', 256}
     * }
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST    => ['onKernelRequest', 16],
            KernelEvents::CONTROLLER => ['onKernelController', 256]
        ];
    }

    /**
     * Handles effective restrictions independently of Permission registration
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        try {
            [$action, $target] = $this->target($event->getRequest());
            $requirements = $target[FeatureRequirements::class] ?? new FeatureRequirements($action);
            if (!$requirements instanceof FeatureRequirements) {
                throw new LogicException('Invalid target Feature requirements.');
            }

            $references = $requirements->getReferences($action);
        } catch (Throwable $throwable) {
            throw new LogicException('Feature selected action resolution failed.', previous: $throwable);
        }

        $authority = $this->authentication->authority($event, $references->getNames() !== []);
        try {
            $context = $authority?->resolve();
        } catch (AuthenticationRequired $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Feature authority resolution failed.', previous: $failure);
        }

        try {
            $context = $this->admission->admit($references, $context);
        } catch (FeatureUnavailable $featureUnavailable) {
            throw new ResourceNotFound('The target is unavailable.', previous: $featureUnavailable);
        }

        $this->authentication->publish($event, $context);
    }

    /**
     * Validates that native resolution has not substituted different action code
     */
    public function onKernelController(ControllerEvent $event): void
    {
        try {
            [$action] = $this->target($event->getRequest());
            $action->assertMatches($this->controllers->action($event->getController()));
        } catch (Throwable $throwable) {
            throw new LogicException('Feature controller association failed.', previous: $throwable);
        }
    }

    /**
     * Returns freshly trusted route defaults and their exact native selected action
     *
     * @return array{SelectedAction, array<string, mixed>}
     */
    private function target(Request $request): array
    {
        $target = $this->targets->matchRequest($request);
        if (
            !isset($target['_controller'], $target['_route']) ||
            $target['_controller'] !== $request->attributes->get('_controller') ||
            $target['_route'] !== $request->attributes->get('_route')
        ) {
            throw new LogicException('The selected Feature target does not match native routing.');
        }

        return [$this->controllers->action($target['_controller']), $target];
    }
}
