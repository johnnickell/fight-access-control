<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\TypeContracts;

use CodeIgniter\HTTP\RequestInterface as CodeIgniterRequest;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware as LaravelPermission;
use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Illuminate\Http\Request as LaravelRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Rejects incompatible HTTP families and callback contracts
 */
function invalidHttpContracts(
    PermissionMiddleware $psr,
    ServerRequestInterface $psrRequest,
    RequestHandlerInterface $handler,
    Request $request,
    RequestMatcherInterface $matcher,
    LaravelPermission $laravel,
    LaravelRequest $laravelRequest,
    RequestContexts $contexts,
    CodeIgniterRequest $codeIgniterRequest,
    SecurityContext $context,
    PermissionDeclaration $declaration
): void {
    $psr->process($request, $handler);
    new PermissionMiddleware($declaration, static fn(ServerRequestInterface $request): SecurityContext => $context);
    new PermissionSubscriber($matcher, static fn(ServerRequestInterface $request): null => null);
    $laravel->handle($laravelRequest, static fn(LaravelRequest $request): string => 'not a native response');
    $contexts->set($codeIgniterRequest, $declaration);
    $contexts->get($request);
    $requireResponse = static function (Response $response): void {
    };
    $requireResponse($psr->process($psrRequest, $handler));
    $requireContext = static function (SecurityContext $context): void {
    };
    $requireContext($contexts->get($codeIgniterRequest));
}
