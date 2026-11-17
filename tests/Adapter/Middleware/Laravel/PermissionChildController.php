<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\Response;

#[RequiresPermission('AGENT_PROFILE_READ')]
#[RequiresPermission('AGENT_PROFILE_READ')]
final class PermissionChildController extends PermissionParentController
{
    #[RequiresPermission('AGENT_PROFILE_READ')]
    public function handle(NativeFormRequest $request, RecordingActionDependency $work, string $id): Response
    {
        return parent::handle($request, $work, $id);
    }
}
