<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class NativeController
{
    public static int $constructed = 0;

    public static int $handled = 0;

    public static ?Closure $work = null;

    public function __construct()
    {
        ++self::$constructed;
    }

    public function slim(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ++self::$handled;
        if (self::$work !== null) {
            (self::$work)();
        }

        return $response->withStatus(204);
    }

    public function yii(ServerRequestInterface $request): ResponseInterface
    {
        ++self::$handled;
        if (self::$work !== null) {
            (self::$work)();
        }

        return new Response(204);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->slim($request, $response);
    }
}
