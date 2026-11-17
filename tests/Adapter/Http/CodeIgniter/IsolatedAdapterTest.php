<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Cache\ResponseCache;
use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\ContentSecurityPolicy;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Router;
use Composer\Autoload\ClassLoader;
use Config\App;
use Config\Cache;
use Config\Cookie;
use Config\Services;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeCodeIgniter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Test\AccessControl\Adapter\Http\OrdinaryFailureCases;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(PermissionFilter::class)]
#[CoversClass(RequestContexts::class)]
#[CoversClass(SafeErrorResponder::class)]
#[CoversClass(SafeCodeIgniter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IsolatedAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        // Load native class definitions, not CI's filesystem-discovering application/test bootstrap.
        // PHPUnit process isolation protects the other framework fixtures' global configuration.
        $vendor = dirname(__DIR__, 4).'/vendor/codeigniter4/framework/';
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addPsr4('Config\\', $vendor.'app/Config/');
        }

        defined('WRITEPATH') || define('WRITEPATH', '/unit-no-storage/');
        defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');
        require_once $vendor.'system/Common.php';
        require_once __DIR__.'/IsolatedServices.php';
        Services::injectMock('responsecache', new ResponseCache(
            $this->createStub(Cache::class),
            $this->createStub(CacheInterface::class)
        ));
        Services::injectMock('csp', $this->createStub(ContentSecurityPolicy::class));
        BaseConfig::$override = false;
        Factories::define('config', Cookie::class, Cookie::class);
    }

    public function test_filter_binds_controlled_route_context_and_clears_it_before_public_admission(): void
    {
        $environment = new AdmissionEnvironment();
        $contexts = new RequestContexts();
        $request = $this->createStub(IncomingRequest::class);
        $router = $this->createStub(Router::class);
        $router->method('getMatchedRoute')->willReturn(['target', 'controller']);
        $declaration = new PermissionDeclaration(AdmissionMode::AUTHENTICATED);
        $router->method('getMatchedRouteOptions')->willReturnCallback(static function () use (&$declaration): array {
            return [PermissionDeclaration::class => $declaration];
        });
        $calls = 0;
        $filter = new PermissionFilter(
            static fn(): Router => $router,
            new RequestAuthentication(static function () use ($environment, &$calls): RequestAuthority {
                ++$calls;

                return $environment->user();
            }, $contexts),
            $this->errors()
        );
        self::assertNull($filter->before($request));
        self::assertNotNull($contexts->get($request));
        $declaration = new PermissionDeclaration(AdmissionMode::PUBLIC);
        self::assertNull($filter->before($request));
        self::assertNull($contexts->get($request));
        self::assertSame(1, $calls);
        $filter->after($request, $this->createStub(ResponseInterface::class));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        yield 'arguments' => ['arguments'];
        yield 'no matched route' => ['missing'];
        yield 'malformed declaration' => ['declaration'];
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_filter_input_returns_only_the_safe_error_response(string $fault): void
    {
        $request = $this->createStub(IncomingRequest::class);
        $router = $this->createStub(Router::class);
        $router->method('getMatchedRoute')->willReturn($fault === 'missing' ? null : ['target', 'controller']);
        $router->method('getMatchedRouteOptions')->willReturn([PermissionDeclaration::class => 'forged']);
        $response = $this->response();
        $filter = new PermissionFilter(
            static fn(): Router => $router,
            new RequestAuthentication(static function (): never {
                self::fail('Invalid target must not authenticate.');
            }, new RequestContexts()),
            $this->errors($response)
        );

        self::assertSame($response, $filter->before($request, $fault === 'arguments' ? ['forged'] : null));
    }

    public function test_safe_response_projects_headers_and_reports_the_original_once(): void
    {
        $failure = new AuthenticationRequired('private');
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with($failure);
        $projector = $this->createStub(SafeHttpProjector::class);
        $projector->method('project')->willReturn(new SafeHttpPresentation(
            metadata: new SafeHttpMetadata(challenge: 'Signature realm="unit"')
        ));
        $response = $this->createMock(ResponseInterface::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->method('setJSON')->willReturnSelf();
        $response->expects(self::once())->method('setHeader')
            ->with('WWW-Authenticate', 'Signature realm="unit"')->willReturnSelf();
        $errors = new SafeErrorResponder(
            $this->config(),
            $diagnostics,
            $projector,
            static fn(): ResponseInterface => $response
        );

        self::assertSame($response, $errors->respond($failure));
    }

    public function test_default_native_response_is_fresh_and_contains_only_the_finite_safe_envelope(): void
    {
        $errors = new SafeErrorResponder($this->config(), $this->createStub(SafeHttpDiagnostics::class));
        $response = $errors->respond(new RuntimeException('private failure'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getBody());
        self::assertSame('application/json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
    }

    public function test_primary_render_failure_uses_fresh_fallback_and_double_failure_stays_safe(): void
    {
        $calls = 0;
        $response = $this->response();
        $errors = new SafeErrorResponder(
            $this->config(),
            $this->createStub(SafeHttpDiagnostics::class),
            responses: static function () use (&$calls, $response): ResponseInterface {
                if (++$calls === 1) {
                    throw new RuntimeException('primary render failure');
                }

                return $response;
            }
        );
        self::assertSame($response, $errors->respond(new RuntimeException('private')));
        self::assertSame(2, $calls);
        $errors = new SafeErrorResponder(
            $this->config(),
            $this->createStub(SafeHttpDiagnostics::class),
            responses: static function (): never {
                throw new RuntimeException('all configured rendering failed');
            }
        );
        $fallback = $errors->respond(new RuntimeException('private'));
        self::assertSame(500, $fallback->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $fallback->getBody());
    }

    /** @return iterable<string, array{bool}> */
    public static function loopOutcomes(): iterable
    {
        yield 'native response' => [false];
        yield 'failure after partial output' => [true];
    }

    #[DataProvider('loopOutcomes')]
    public function test_native_loop_wrapper_preserves_success_and_cleans_buffer_before_safe_failure(
        bool $failed
    ): void {
        $response = $this->response();
        $app = new SafeCodeIgniter($this->config(), $this->errors($response));
        $request = $this->createStub(IncomingRequest::class);
        $request->method('getMethod')->willReturnCallback(static function () use ($failed): string {
            if ($failed) {
                echo 'private partial output';
                throw new RuntimeException('private request failure');
            }

            return 'CLI';
        });
        // Arrange the inherited protected request/response seam without executing a native application.
        new ReflectionProperty($app, 'request')->setValue($app, $request);
        new ReflectionProperty($app, 'response')->setValue($app, $response);
        $cache = $this->createStub(Cache::class);
        $level = ob_get_level();
        if ($failed) {
            new ReflectionMethod($app, 'outputBufferingStart')->invoke($app);
        }

        self::assertSame($response, new ReflectionMethod($app, 'handleRequest')->invoke($app, null, $cache));
        self::assertSame($level, ob_get_level());
    }

    /** @return iterable<string, array{string, int}> */
    public static function permissionOutcomes(): iterable
    {
        yield 'missing declaration' => ['missing', 500];
        yield 'CLI request' => ['cli', 500];
        yield 'anonymous' => ['anonymous', 401];
        yield 'expired authentication' => ['expired', 401];
        yield 'no grant' => ['denied', 403];
        yield 'provider fault' => ['fault', 500];
    }

    #[DataProvider('permissionOutcomes')]
    public function test_permission_refusals_and_faults_clear_context_and_use_safe_type_policy(
        string $outcome,
        int $status
    ): void {
        $environment = new AdmissionEnvironment();
        $contexts = new RequestContexts();
        $request = $this->createStub(IncomingRequest::class);
        if ($outcome === 'cli') {
            $request = $this->createStub(CLIRequest::class);
        }

        if ($request instanceof IncomingRequest) {
            $contexts->set($request, $environment->user()->resolve());
        }

        $router = $this->createStub(Router::class);
        $router->method('getMatchedRoute')->willReturn(['target', 'controller']);
        $router->method('getMatchedRouteOptions')->willReturn([
            PermissionDeclaration::class => $outcome === 'missing' ? null : new PermissionDeclaration(
                AdmissionMode::PROTECTED,
                [PermissionName::fromString('AGENT_PROFILE_READ')]
            )
        ]);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('setStatusCode')->with($status)->willReturnSelf();
        $response->method('setJSON')->willReturnSelf();
        $projector = $this->createStub(SafeHttpProjector::class);
        $projector->method('project')->willReturnCallback(static fn(): SafeHttpPresentation => new SafeHttpPresentation(
            metadata: $status === 401 ? new SafeHttpMetadata(challenge: 'Signature realm="unit"') : null
        ));
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report');
        $filter = new PermissionFilter(
            static fn(): Router => $router,
            new RequestAuthentication(static fn(): ?RequestAuthority => match ($outcome) {
                'missing', 'cli' => self::fail('Invalid selection must not authenticate.'),
                'anonymous' => null,
                default => $environment->user(
                    grant: $outcome !== 'denied',
                    valid: $outcome !== 'expired',
                    fault: $outcome === 'fault' ? new RuntimeException('private provider fault') : null
                )
            }, $contexts),
            new SafeErrorResponder(
                $this->config(),
                $diagnostics,
                $projector,
                static fn(): ResponseInterface => $response
            )
        );

        self::assertSame($response, $filter->before($request));
        if ($request instanceof IncomingRequest) {
            self::assertNull($contexts->get($request));
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function ordinaryFailures(): iterable
    {
        yield from OrdinaryFailureCases::cases();
    }

    #[DataProvider('ordinaryFailures')]
    public function test_error_bridge_passes_only_the_encoded_safe_envelope_to_the_response(
        string $case,
        int $status,
        string $message
    ): void {
        $failure = OrdinaryFailureCases::failure($case);
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with($failure);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('setStatusCode')->with($status)->willReturnSelf();
        $response->expects(self::once())->method('setJSON')->with(self::callback(
            static function (string $json) use ($status, $message): bool {
                OrdinaryFailureCases::assertBody($json, $status, $message);

                return true;
            }
        ))->willReturnSelf();
        $errors = new SafeErrorResponder(
            $this->config(),
            $diagnostics,
            responses: static fn(): ResponseInterface => $response
        );

        self::assertSame($response, $errors->respond($failure));
    }

    public function test_failed_partial_render_is_discarded_and_diagnostics_failure_is_not_reported_again(): void
    {
        $failure = new RuntimeException('private original');
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with($failure);
        $partial = $this->createStub(ResponseInterface::class);
        $partial->method('setStatusCode')->willReturnSelf();
        $partial->method('setJSON')->willThrowException(new RuntimeException('private encoder failure'));
        $fallback = $this->createMock(ResponseInterface::class);
        $fallback->expects(self::once())->method('setStatusCode')->with(500)->willReturnSelf();
        $fallback->expects(self::once())->method('setJSON')
            ->with('{"status":"error","message":"Internal server error."}')->willReturnSelf();
        $calls = 0;
        $errors = new SafeErrorResponder(
            $this->config(),
            $diagnostics,
            responses: static function () use (&$calls, $partial, $fallback): ResponseInterface {
                return ++$calls === 1 ? $partial : $fallback;
            }
        );
        self::assertSame($fallback, $errors->respond($failure));
        self::assertSame(2, $calls);

        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->willThrowException(new RuntimeException('private sink'));
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('setStatusCode')->with(500)->willReturnSelf();
        $response->expects(self::once())->method('setJSON')
            ->with('{"status":"error","message":"Internal server error."}')->willReturnSelf();
        $errors = new SafeErrorResponder(
            $this->config(),
            $diagnostics,
            responses: static fn(): ResponseInterface => $response
        );
        self::assertSame($response, $errors->respond($failure));
    }

    private function config(): App
    {
        return $this->createStub(App::class);
    }

    private function response(): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->method('setJSON')->willReturnSelf();
        $response->method('setBody')->willReturnSelf();

        return $response;
    }

    private function errors(?ResponseInterface $response = null): SafeErrorResponder
    {
        $response ??= $this->response();

        return new SafeErrorResponder(
            $this->config(),
            $this->createStub(SafeHttpDiagnostics::class),
            responses: static fn(): ResponseInterface => $response
        );
    }
}
