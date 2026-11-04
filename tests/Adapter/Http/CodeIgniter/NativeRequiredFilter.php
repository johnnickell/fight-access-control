<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use RuntimeException;

final class NativeRequiredFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if ($request->getHeaderLine('X-Required-Fault') !== '') {
            throw new RuntimeException('Outside selected handleRequest boundary.');
        }

        if ($request->getHeaderLine('X-Required-Refusal') !== '') {
            return new Response(new App())->setStatusCode(409)->setBody('Required guard refusal.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        return $response->setHeader('X-Required-After', 'preserved');
    }
}
