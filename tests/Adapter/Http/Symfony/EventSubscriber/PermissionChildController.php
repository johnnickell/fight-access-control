<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[RequiresPermission('AGENT_PROFILE_READ')]
final class PermissionChildController extends PermissionParentController
{
    #[RequiresPermission('ACCOUNT_MANAGE')]
    public function handle(Request $request, string $id): Response
    {
        return parent::handle($request, $id);
    }
}
