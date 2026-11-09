<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class GateChain implements MiddlewareInterface
{
    public function __construct(private MiddlewareInterface $first, private MiddlewareInterface $second)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $next = new readonly class ($this->second, $handler) implements RequestHandlerInterface {
            public function __construct(private MiddlewareInterface $second, private RequestHandlerInterface $handler)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->second->process($request, $this->handler);
            }
        };

        return $this->first->process($request, $next);
    }
}
