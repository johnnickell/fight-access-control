<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Laravel;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

/**
 * Class RequestAuthentication
 *
 * Shares trusted provider selection only inside an active native middleware chain.
 */
final readonly class RequestAuthentication
{
    /** @var WeakMap<Request, array{selected: bool, authority: ?RequestAuthority, context: ?SecurityContext}> */
    private WeakMap $active;

    /**
     * Constructs RequestAuthentication
     *
     * @phpstan-param Closure(Request): ?RequestAuthority $authority
     */
    public function __construct(private Closure $authority)
    {
        $this->active = new WeakMap();
    }

    /**
     * Handles cooperating gates without accepting caller-copyable context as authority
     *
     * @phpstan-param Closure(Request): Response $next
     * @phpstan-param Closure(?RequestAuthority): ?SecurityContext $admit
     */
    public function admit(Request $request, Closure $next, Closure $admit, bool $authenticate): Response
    {
        $owner = !isset($this->active[$request]);
        if ($owner) {
            $request->attributes->remove(SecurityContext::class);
            $this->active[$request] = ['selected' => false, 'authority' => null, 'context' => null];
        }

        try {
            $state = $this->active[$request];
            if ($authenticate && !$state['selected']) {
                try {
                    $state['authority'] = ($this->authority)($request);
                } catch (AuthenticationRequired $failure) {
                    throw $failure;
                } catch (Throwable $failure) {
                    throw new LogicException('Request authority selection failed.', previous: $failure);
                }

                $state['selected'] = true;
                $this->active[$request] = $state;
            }

            $context = $admit($authenticate ? $state['authority'] : null);
            if ($context !== null) {
                $state['context'] = $context;
                $this->active[$request] = $state;
            }

            $request->attributes->remove(SecurityContext::class);
            if ($state['context'] !== null) {
                $request->attributes->set(SecurityContext::class, $state['context']);
            }

            return $next($request);
        } finally {
            if ($owner) {
                unset($this->active[$request]);
            }
        }
    }
}
