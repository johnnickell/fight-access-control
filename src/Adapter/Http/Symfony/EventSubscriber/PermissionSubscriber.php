<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Closure;
use Fight\AccessControl\Adapter\Http\Symfony\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\Symfony\SelectedController;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionRequirements;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use LogicException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Throwable;

/**
 * Class PermissionSubscriber
 *
 * Select explicitly after RouterListener and before controller construction or validation.
 * The trusted matcher must use the same immutable routes as the native router.
 */
final readonly class PermissionSubscriber implements EventSubscriberInterface
{
    private RequestAuthentication $authentication;

    /**
     * Constructs PermissionSubscriber
     *
     * @phpstan-param (Closure(Request): ?RequestAuthority)|RequestAuthentication $authority
     */
    public function __construct(
        private RequestMatcherInterface $targets,
        Closure|RequestAuthentication $authority,
        private PermissionAdmission $admission = new PermissionAdmission(),
        private SelectedController $selectedController = new SelectedController()
    ) {
        if ($authority instanceof Closure) {
            $authority = new RequestAuthentication($authority);
        }

        $this->authentication = $authority;
    }

    /**
     * Returns the qualified post-routing admission priority for main and subrequests
     *
     * @phpstan-return array{
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
     * Handles admission from trusted matched defaults rather than copied request metadata
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        // Start the dispatch lifetime even when target metadata fails before authentication.
        $this->authentication->authority($event, false);
        try {
            [$action, $target] = $this->target($event->getRequest());
            $route = $target[PermissionDeclaration::class] ?? null;
            if ($route !== null && !$route instanceof PermissionDeclaration) {
                throw new LogicException('Invalid target permission declaration.');
            }

            $declaration = new PermissionRequirements($action, $route)->getDeclaration($action);
        } catch (Throwable $throwable) {
            throw new LogicException('Permission selected action resolution failed.', previous: $throwable);
        }

        $authority = $this->authentication->authority(
            $event,
            $declaration !== null && $declaration->mode !== AdmissionMode::PUBLIC
        );
        $context = $this->admission->admit($declaration, $authority);
        $this->authentication->publish($event, $context);
    }

    /**
     * Validates that native resolution has not substituted different action code
     */
    public function onKernelController(ControllerEvent $event): void
    {
        try {
            [$action] = $this->target($event->getRequest());
            $action->assertMatches($this->selectedController->action($event->getController()));
        } catch (Throwable $throwable) {
            throw new LogicException('Permission controller association failed.', previous: $throwable);
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
            throw new LogicException('The selected permission target does not match native routing.');
        }

        return [$this->selectedController->action($target['_controller']), $target];
    }
}
