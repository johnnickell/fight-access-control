<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\Response;

#[RequiresPermission('AGENT_PROFILE_READ')]
class PermissionParentController
{
    #[RequiresPermission('AGENT_PROFILE_UPDATE')]
    public function handle(NativeFormRequest $request, RecordingActionDependency $work, string $id): Response
    {
        return new ControllerWithFormRequest()->handle($request, $work, $id);
    }

    public function __invoke(NativeFormRequest $request, RecordingActionDependency $work, string $id): Response
    {
        return $this->handle($request, $work, $id);
    }
}
