<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Symfony\Component\HttpFoundation\Response;

final class NativeController
{
    public static NativeTrace $trace;

    public function __construct()
    {
        self::$trace->events[] = 'construct';
    }

    public function handle(NativeFormRequest $request, NativeWork $work, string $id): Response
    {
        self::$trace->events[] = 'action';
        self::$trace->contexts[] = $request->attributes->get(SecurityContext::class);
        $work->dispatch();

        return new Response('', 204, ['X-Action' => $id]);
    }

    public function __invoke(NativeFormRequest $request, NativeWork $work, string $id): Response
    {
        return $this->handle($request, $work, $id);
    }
}
