<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class PermissionValidation implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        PermissionController::$trace[] = 'validation';

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        PermissionController::$trace[] = 'after';

        return null;
    }
}
