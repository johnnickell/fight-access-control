<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FeatureNativeChild extends FeatureNativeParent
{
    #[FeatureFlag('second')]
    public function slim(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return parent::slim($request, $response);
    }

    #[FeatureFlag('second')]
    public function yii(ServerRequestInterface $request): ResponseInterface
    {
        return parent::yii($request);
    }
}
