<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Application\Attribute\Validation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresPermission('AGENT_PROFILE_READ')]
class PermissionParentController
{
    public function __construct()
    {
        NativeController::$trace->events[] = 'construct';
    }

    #[RequiresPermission('AGENT_PROFILE_READ')]
    #[RequiresPermission('AGENT_PROFILE_READ')]
    #[Validation(rules: [['field' => 'name', 'label' => 'Name', 'rules' => 'alpha']])]
    #[IsGranted('CONSUMER_RECORD', subject: 'id', statusCode: 403)]
    public function handle(Request $request, string $id): Response
    {
        NativeController::$trace->events[] = 'action';
        NativeController::$trace->contexts[] = $request->attributes->get(SecurityContext::class);
        if (NativeController::$work !== null) {
            return (NativeController::$work)($request, $id);
        }

        return new Response('', 204, ['X-Action' => $id]);
    }

    #[RequiresPermission('invalid permission')]
    public function malformed(): Response
    {
        return new Response('unreachable');
    }

    public function __invoke(Request $request, string $id): Response
    {
        return $this->handle($request, $id);
    }
}
