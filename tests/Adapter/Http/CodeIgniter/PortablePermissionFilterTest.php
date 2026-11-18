<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Router;
use Composer\Autoload\ClassLoader;
use Config\App;
use Fight\AccessControl\Adapter\Http\CodeIgniter\MatchedAction;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionFilter::class)]
#[CoversClass(MatchedAction::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PortablePermissionFilterTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addPsr4('Config\\', dirname(__DIR__, 4).'/vendor/codeigniter4/framework/app/Config/');
        }
    }

    public static function targets(): iterable
    {
        yield 'attribute-only class' => ['inherited', null, true, 200];
        yield 'invokable' => ['__invoke', null, true, 200];
        yield 'denied class' => ['inherited', null, false, 403];
        yield 'override preserves parent method' => ['handle', null, true, 403];
        yield 'public cannot weaken class' => ['inherited', AdmissionMode::PUBLIC, true, 500];
        yield 'authenticated cannot weaken class' => ['inherited', AdmissionMode::AUTHENTICATED, true, 500];
        yield 'additional route is conjunctive' => ['inherited', AdmissionMode::PROTECTED, true, 403];
        yield 'malformed attribute' => ['malformed', null, true, 500];
    }

    #[DataProvider('targets')]
    public function test_effective_native_target_requirements_precede_authentication_and_work(
        string $method,
        ?AdmissionMode $mode,
        bool $grant,
        int $status
    ): void {
        $router = $this->createStub(Router::class);
        $router->method('getMatchedRoute')->willReturn(['target', 'controller']);
        $router->method('controllerName')->willReturn(PermissionController::class);
        $router->method('methodName')->willReturn($method);
        $router->method('getMatchedRouteOptions')->willReturn($mode === null ? [] : [
            PermissionDeclaration::class => new PermissionDeclaration(
                $mode,
                $mode === AdmissionMode::PROTECTED ? [PermissionName::fromString('ROUTE_READ')] : []
            )
        ]);
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $contexts = new RequestContexts();
        $request = $this->createStub(IncomingRequest::class);
        // Incoming context is neither proof of admission nor a source of grants.
        $contexts->set($request, $environment->user()->resolve());
        $authentication = new RequestAuthentication(static function () use ($environment, $grant, &$calls) {
            ++$calls;

            return $environment->user(grant: $grant);
        }, $contexts);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($status === 200 ? self::never() : self::once())
            ->method('setStatusCode')->with($status)->willReturnSelf();
        $response->method('setJSON')->willReturnSelf();

        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects($status === 200 ? self::never() : self::once())->method('report');
        $errors = new SafeErrorResponder(
            $this->createStub(App::class),
            $diagnostics,
            responses: static fn(): ResponseInterface => $response
        );
        $filter = new PermissionFilter(static fn(): Router => $router, $authentication, $errors);
        PermissionController::$trace = [];

        self::assertSame($status === 200 ? null : $response, $filter->before($request));
        self::assertSame($status === 500 ? 0 : 1, $calls);
        self::assertSame($status === 200, $contexts->get($request) !== null);
        self::assertSame([], PermissionController::$trace);
    }

    public function test_two_closure_routes_keep_distinct_policy_and_fresh_authority_on_reuse(): void
    {
        $callback = static fn(): null => null;
        $router = $this->createStub(Router::class);
        $router->method('getMatchedRoute')->willReturn(['route', $callback]);
        $router->method('controllerName')->willReturn($callback);
        $declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ')
        ]);
        $router->method('getMatchedRouteOptions')->willReturnCallback(static function () use (&$declaration) {
            return [PermissionDeclaration::class => $declaration];
        });
        $environment = new AdmissionEnvironment();
        $contexts = new RequestContexts();
        $calls = 0;
        $authentication = new RequestAuthentication(static function () use ($environment, &$calls): RequestAuthority {
            ++$calls;

            return $environment->user();
        }, $contexts);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->method('setJSON')->willReturnSelf();
        $filter = new PermissionFilter(static fn(): Router => $router, $authentication, new SafeErrorResponder(
            $this->createStub(App::class),
            $this->createStub(SafeHttpDiagnostics::class),
            responses: static fn(): ResponseInterface => $response
        ));
        $request = $this->createStub(IncomingRequest::class);
        self::assertNull($filter->before($request));
        $first = $contexts->get($request);
        $declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('OTHER_READ')]);
        self::assertSame($response, $filter->before($request));
        self::assertNull($contexts->get($request));
        $declaration = new PermissionDeclaration(AdmissionMode::AUTHENTICATED);
        $nested = $this->createStub(IncomingRequest::class);
        self::assertNull($filter->before($nested));
        self::assertNotSame($first, $contexts->get($nested));
        self::assertSame(3, $calls);
    }
}
