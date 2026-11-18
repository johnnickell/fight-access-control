<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Application\Attribute\Validation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('CONSUMER_SCOPE', statusCode: 403)]
final class NativeController
{
    public static NativeTrace $trace;

    public static ?Closure $work = null;

    public function __construct()
    {
        self::$trace->events[] = 'construct';
    }

    #[IsGranted('CONSUMER_RECORD', subject: 'id', statusCode: 403)]
    #[Validation(rules: [['field' => 'name', 'label' => 'Name', 'rules' => 'alpha']])]
    public function handle(Request $request, string $id): Response
    {
        return $this($request, $id);
    }

    #[IsGranted('CONSUMER_RECORD', subject: 'id', statusCode: 403)]
    #[Validation(rules: [['field' => 'name', 'label' => 'Name', 'rules' => 'alpha']])]
    public function __invoke(Request $request, string $id): Response
    {
        self::$trace->events[] = 'action';
        self::$trace->contexts[] = $request->attributes->get(SecurityContext::class);
        if (self::$work !== null) {
            return (self::$work)($request, $id);
        }

        return new Response('', 204, ['X-Action' => $id]);
    }
}
