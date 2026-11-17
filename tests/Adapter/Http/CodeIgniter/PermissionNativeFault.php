<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Attribute;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Attributes\RouteAttributeInterface;
use RuntimeException;

#[Attribute(Attribute::TARGET_METHOD)]
final class PermissionNativeFault implements RouteAttributeInterface
{
    public function __construct()
    {
        throw new RuntimeException('private-native-attribute-sentinel');
    }

    public function before(RequestInterface $request): RequestInterface|ResponseInterface|null
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        return null;
    }
}
