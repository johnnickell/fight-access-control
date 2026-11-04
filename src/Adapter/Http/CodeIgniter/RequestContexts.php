<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\HTTP\RequestInterface;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use WeakMap;

/**
 * Class RequestContexts
 *
 * Holds admitted short-request snapshots by exact native request identity, never ambient caller identity.
 */
final readonly class RequestContexts
{
    /** @phpstan-var WeakMap<RequestInterface, SecurityContext> */
    private WeakMap $contexts;

    /**
     * Constructs RequestContexts
     */
    public function __construct()
    {
        $this->contexts = new WeakMap();
    }

    /**
     * Returns the selected snapshot for this request only
     */
    public function get(RequestInterface $request): ?SecurityContext
    {
        return $this->contexts[$request] ?? null;
    }

    /**
     * Records a complete admitted snapshot
     */
    public function set(RequestInterface $request, SecurityContext $context): void
    {
        $this->contexts[$request] = $context;
    }

    /**
     * Removes any snapshot before attempting fresh admission
     */
    public function clear(RequestInterface $request): void
    {
        unset($this->contexts[$request]);
    }
}
