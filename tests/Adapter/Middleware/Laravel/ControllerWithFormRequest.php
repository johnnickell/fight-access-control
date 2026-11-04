<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Symfony\Component\HttpFoundation\Response;

final class ControllerWithFormRequest
{
    public static RequestExecutionTrace $trace;

    public function __construct()
    {
        self::$trace->events[] = 'construct';
    }

    public function handle(NativeFormRequest $request, RecordingActionDependency $work, string $id): Response
    {
        self::$trace->events[] = 'action';
        self::$trace->contexts[] = $request->attributes->get(SecurityContext::class);
        $work->dispatch();

        return new Response('', 204, ['X-Action' => $id]);
    }

    public function __invoke(NativeFormRequest $request, RecordingActionDependency $work, string $id): Response
    {
        return $this->handle($request, $work, $id);
    }
}
