<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Fight\Common\Application\Error\Exception\InvalidInput;
use RuntimeException;

final class NativeValidator implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        NativeController::$events[] = 'validator';
        if ($request->getHeaderLine('X-Invalid') !== '') {
            throw new InvalidInput('PRIVATE VALIDATION');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        NativeController::$events[] = 'after';
        if ($request->getHeaderLine('X-After-Fault') !== '') {
            throw new RuntimeException('PRIVATE AFTER FILTER');
        }

        return null;
    }
}
