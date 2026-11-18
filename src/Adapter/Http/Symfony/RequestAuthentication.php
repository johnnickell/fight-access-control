<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\Symfony;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Throwable;
use WeakMap;

/**
 * Class RequestAuthentication
 *
 * Shares actual request-owned providers only within one native request dispatch.
 */
final readonly class RequestAuthentication
{
    /** @var WeakMap<RequestEvent, array{selected: bool, authority: ?RequestAuthority, context: ?SecurityContext}> */
    private WeakMap $requests;

    /**
     * Constructs RequestAuthentication
     *
     * @phpstan-param Closure(Request): ?RequestAuthority $authority
     */
    public function __construct(private Closure $authority)
    {
        $this->requests = new WeakMap();
    }

    /**
     * Returns one trusted provider selection without trusting incoming context attributes
     */
    public function authority(RequestEvent $event, bool $authenticate): ?RequestAuthority
    {
        if (!isset($this->requests[$event])) {
            $event->getRequest()->attributes->remove(SecurityContext::class);
            $this->requests[$event] = ['selected' => false, 'authority' => null, 'context' => null];
        }

        $state = $this->requests[$event];
        if ($authenticate && !$state['selected']) {
            try {
                $state['authority'] = ($this->authority)($event->getRequest());
            } catch (AuthenticationRequired $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw new LogicException('Request authority selection failed.', previous: $failure);
            }

            $state['selected'] = true;
            $this->requests[$event] = $state;
        }

        return $authenticate ? $state['authority'] : null;
    }

    /**
     * Sets cooperating gates' trusted context without making public admission a grant
     */
    public function publish(RequestEvent $event, ?SecurityContext $context): void
    {
        $state = $this->requests[$event];
        if ($context !== null) {
            $state['context'] = $context;
            $this->requests[$event] = $state;
        }

        if ($state['context'] !== null) {
            $event->getRequest()->attributes->set(SecurityContext::class, $state['context']);
        }
    }
}
