<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Router\Router;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use LogicException;
use Throwable;
use WeakMap;

/**
 * Class RequestAuthentication
 *
 * Shares provider selection across cooperating gates within one native router/request dispatch.
 */
final readonly class RequestAuthentication
{
    /** @var WeakMap<IncomingRequest, array{router: Router, gates: list<string>, selected: bool, authority: ?RequestAuthority}> */
    private WeakMap $requests;

    /**
     * Constructs RequestAuthentication
     *
     * @phpstan-param Closure(IncomingRequest): ?RequestAuthority $authority
     */
    public function __construct(private Closure $authority, private RequestContexts $contexts)
    {
        $this->requests = new WeakMap();
    }

    /**
     * Starts fresh admission when native routing or an already visited gate starts another attempt
     */
    public function begin(IncomingRequest $request, Router $router, string $gate): void
    {
        $state = $this->requests[$request] ?? null;
        if ($state === null || $state['router'] !== $router || in_array($gate, $state['gates'], true)) {
            $this->clear($request);
            $state = ['router' => $router, 'gates' => [], 'selected' => false, 'authority' => null];
        }

        $state['gates'][] = $gate;
        $this->requests[$request] = $state;
    }

    /**
     * Returns the one selected provider or deliberate anonymous selection
     */
    public function authority(IncomingRequest $request): ?RequestAuthority
    {
        try {
            $state = $this->requests[$request];
            if (!$state['selected']) {
                $state['authority'] = ($this->authority)($request);
                $state['selected'] = true;
                $this->requests[$request] = $state;
            }

            return $state['authority'];
        } catch (AuthenticationRequired $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Request authority resolution failed.', previous: $failure);
        }
    }

    /**
     * Records admitted context without erasing a cooperating gate's snapshot
     */
    public function publish(IncomingRequest $request, ?SecurityContext $context): void
    {
        if ($context !== null) {
            $this->contexts->set($request, $context);
        }
    }

    /**
     * Removes authority selection and admitted context after failure or before a new dispatch
     */
    public function clear(IncomingRequest $request): void
    {
        unset($this->requests[$request]);
        $this->contexts->clear($request);
    }
}
