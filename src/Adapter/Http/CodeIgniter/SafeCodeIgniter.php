<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\CodeIgniter;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\RouteCollectionInterface;
use Config\App;
use Config\Cache;
use Throwable;

/**
 * Class SafeCodeIgniter
 *
 * Explicit ordinary-JSON loop selection, not a global exception-handler replacement.
 */
final class SafeCodeIgniter extends CodeIgniter
{
    /**
     * Constructs SafeCodeIgniter
     */
    public function __construct(App $config, private readonly SafeErrorResponder $errors)
    {
        parent::__construct($config);
    }

    /**
     * Handles routing through downstream filters and actions before native raw error handling
     */
    protected function handleRequest(
        ?RouteCollectionInterface $routes,
        Cache $cacheConfig,
        bool $returnResponse = false
    ): ResponseInterface {
        try {
            return parent::handleRequest($routes, $cacheConfig, $returnResponse);
        } catch (Throwable $throwable) {
            $this->outputBufferingEnd();

            return $this->errors->respond($throwable);
        }
    }
}
