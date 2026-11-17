<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

#[FeatureFlag('rollout')]
#[FeatureFlag('rollout')]
class FeatureParent extends Controller
{
    public function __construct()
    {
        NativeController::$events[] = 'construct';
    }

    #[FeatureFlag('parent-gate')]
    public function handle(string $id): ResponseInterface
    {
        NativeController::$events[] = 'action';
        if (NativeController::$work !== null) {
            (NativeController::$work)($this->request);
        }

        return $this->response->setStatusCode(204)->setHeader('X-Action', $id);
    }

    public function __invoke(string $id): ResponseInterface
    {
        return $this->handle($id);
    }
}
