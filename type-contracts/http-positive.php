<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use CodeIgniter\HTTP\RequestInterface as CodeIgniterRequest;
use CodeIgniter\HTTP\ResponseInterface as CodeIgniterResponse;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeCodeIgniter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware as LaravelPermission;
use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Illuminate\Http\Request as LaravelRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Throwable;

/**
 * Proves native HTTP callbacks and results
 */
function httpContracts(
    PermissionMiddleware $psr,
    ServerRequestInterface $psrRequest,
    RequestHandlerInterface $handler,
    PermissionSubscriber $subscriber,
    RequestEvent $event,
    LaravelPermission $laravel,
    LaravelRequest $laravelRequest,
    PermissionFilter $filter,
    CodeIgniterRequest $codeIgniterRequest,
    CodeIgniterResponse $codeIgniterResponse,
    RequestContexts $contexts,
    SafeErrorResponder $errors,
    SafeCodeIgniter $application,
    Throwable $failure
): void {
    $next = static fn(LaravelRequest $request): Response => new Response();

    \PHPStan\dumpType($psr->process(...));
    \PHPStan\dumpType($psr->process($psrRequest, $handler));
    \PHPStan\dumpType(PermissionSubscriber::getSubscribedEvents());
    \PHPStan\dumpType($subscriber->onKernelRequest(...));
    \PHPStan\dumpType($laravel->handle($laravelRequest, $next));
    \PHPStan\dumpType($filter->before($codeIgniterRequest));
    \PHPStan\dumpType($filter->after($codeIgniterRequest, $codeIgniterResponse));
    \PHPStan\dumpType($contexts->get($codeIgniterRequest));
    \PHPStan\dumpType($errors->respond($failure));
    \PHPStan\dumpType($application->run(returnResponse: true));
    \PHPStan\dumpType($event->getRequest());
}
