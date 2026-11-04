<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Attributes\Restrict;

final class NativeController extends Controller
{
    /** @var list<string> */
    public static array $events = [];

    public static ?Closure $work = null;

    public function __construct()
    {
        self::$events[] = 'construct';
    }

    public function handle(string $id): ResponseInterface
    {
        self::$events[] = 'action';
        if (self::$work !== null) {
            (self::$work)($this->request);
        }

        return $this->response->setStatusCode(204)->setHeader('X-Action', $id);
    }

    #[Restrict(environment: 'production')]
    public function restricted(string $id): ResponseInterface
    {
        return $this->handle($id);
    }

    public function __invoke(string $id): ResponseInterface
    {
        return $this->handle($id);
    }
}
