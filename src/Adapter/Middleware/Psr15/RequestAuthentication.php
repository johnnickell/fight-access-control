<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use WeakMap;

/**
 * Class RequestAuthentication
 *
 * Shares trusted provider selection across cooperating gates, never through caller-copyable attributes.
 */
final readonly class RequestAuthentication
{
    /** @var WeakMap<ServerRequestInterface, RequestAuthenticationState> */
    private WeakMap $active;

    /**
     * Constructs RequestAuthentication
     *
     * @phpstan-param Closure(ServerRequestInterface): ?RequestAuthority $authority
     */
    public function __construct(private Closure $authority)
    {
        $this->active = new WeakMap();
    }

    /**
     * Handles one gate and its downstream chain with finally-scoped trusted state
     *
     * @phpstan-param Closure(?RequestAuthority): ?SecurityContext $admit
     */
    public function admit(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        Closure $admit,
        bool $authenticate
    ): ResponseInterface {
        $state = $this->active[$request] ?? new RequestAuthenticationState(
            fn(): ?RequestAuthority => ($this->authority)($request)
        );
        $context = $admit($authenticate ? $state->authority() : null);
        if ($context !== null) {
            $state->context = $context;
        }

        $next = $request->withoutAttribute(SecurityContext::class);
        if ($state->context !== null) {
            $next = $next->withAttribute(SecurityContext::class, $state->context);
        }

        $previous = $this->active[$next] ?? null;
        $this->active[$next] = $state;
        try {
            return $handler->handle($next);
        } finally {
            unset($this->active[$next]);
            if ($previous !== null) {
                $this->active[$next] = $previous;
            }
        }
    }
}
