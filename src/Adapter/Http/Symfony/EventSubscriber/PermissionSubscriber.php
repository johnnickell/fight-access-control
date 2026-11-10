<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Closure;
use Fight\AccessControl\Adapter\Http\Symfony\RequestAuthentication;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use LogicException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

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
        private PermissionAdmission $admission = new PermissionAdmission()
    ) {
        $authentication = $authority;
        if ($authority instanceof Closure) {
            $authentication = new RequestAuthentication($authority);
        }

        $this->authentication = $authentication;
    }

    /**
     * Returns the qualified post-routing admission priority for main and subrequests
     *
     * @phpstan-return array{'kernel.request': array{'onKernelRequest', 16}}
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    /**
     * Handles admission from trusted matched defaults rather than copied request metadata
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $target = $this->targets->matchRequest($request);
        if (
            !isset($target['_controller'], $target['_route']) ||
            $target['_controller'] !== $request->attributes->get('_controller') ||
            $target['_route'] !== $request->attributes->get('_route')
        ) {
            throw new LogicException('The selected permission target does not match native routing.');
        }

        $declaration = $target[PermissionDeclaration::class] ?? null;
        if ($declaration !== null && !$declaration instanceof PermissionDeclaration) {
            throw new LogicException('Invalid target permission declaration.');
        }

        $authority = $this->authentication->authority(
            $event,
            $declaration !== null && $declaration->mode !== AdmissionMode::PUBLIC
        );
        $context = $this->admission->admit($declaration, $authority);
        $this->authentication->publish($event, $context);
    }
}
