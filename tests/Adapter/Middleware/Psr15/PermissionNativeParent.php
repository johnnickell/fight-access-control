<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RequiresPermission('AGENT_PROFILE_READ')]
class PermissionNativeParent extends NativeController
{
    #[RequiresPermission('AGENT_PROFILE_UPDATE')]
    public function slim(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return parent::slim($request, $response);
    }

    #[RequiresPermission('AGENT_PROFILE_UPDATE')]
    public function yii(ServerRequestInterface $request): ResponseInterface
    {
        return parent::yii($request);
    }
}
