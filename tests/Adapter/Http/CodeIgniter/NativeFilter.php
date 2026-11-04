<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;

final class NativeFilter implements FilterInterface
{
    public static PermissionFilter $delegate;

    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        return self::$delegate->before($request, $arguments);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return self::$delegate->after($request, $response, $arguments);
    }
}
