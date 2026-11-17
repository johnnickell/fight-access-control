<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RequiresPermission('AGENT_PROFILE_READ')]
#[RequiresPermission('AGENT_PROFILE_READ')]
final class PermissionNativeChild extends PermissionNativeParent
{
    public function slim(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return parent::slim($request, $response);
    }

    #[RequiresPermission('AGENT_PROFILE_UPDATE')]
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->slim($request, $response);
    }
}
