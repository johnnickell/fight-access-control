<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use CodeIgniter\Events\Events;
use CodeIgniter\Filters\Filters;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Log\Logger;
use CodeIgniter\Router\RouteCollection;
use Composer\InstalledVersions;
use Config\App;
use Config\Feature;
use Config\Filters as FilterConfig;
use Config\Logger as LoggerConfig;
use Config\Modules;
use Config\Routing;
use Fight\AccessControl\Adapter\Http\CodeIgniter\FeatureFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeCodeIgniter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use RuntimeException;
use Stringable;
use Throwable;

final class NativeApplication
{
    /** @var list<array{type: class-string}> */
    public array $reports = [];

    public bool $sinkFails = false;

    public bool $requiredFilters = false;

    /** @var list<string> */
    public array $nativeLogs = [];

    public readonly RequestContexts $contexts;

    public readonly RequestAuthentication $authentication;

    public ?FeatureAdmission $featureAdmission = null;

    public mixed $featureRequirements = null;

    /** @var list<string> */
    public array $featureGates = ['feature'];

    public readonly PermissionFilter $filter;

    public readonly SafeErrorResponder $errors;

    public readonly App $config;

    /** @param Closure(IncomingRequest): ?RequestAuthority $authority */
    public function __construct(
        Closure $authority,
        public mixed $declaration,
        public mixed $handler = '\\'.NativeController::class.'::handle/$1',
        ?SafeHttpProjector $projector = null
    ) {
        $this->config = new App();
        $this->contexts = new RequestContexts();
        $diagnostics = new readonly class ($this) implements SafeHttpDiagnostics {
            public function __construct(private NativeApplication $native)
            {
            }

            public function report(Throwable $failure): void
            {
                $this->native->reports[] = ['type' => $failure::class];
                if ($this->native->sinkFails) {
                    throw new RuntimeException('PRIVATE SINK');
                }
            }
        };
        $this->errors = new SafeErrorResponder($this->config, $diagnostics, $projector);
        $this->authentication = new RequestAuthentication($authority, $this->contexts);
        $this->filter = new PermissionFilter(
            static fn() => Services::router(),
            $this->authentication,
            $this->errors
        );
        NativeFilter::$delegate = $this->filter;
        NativeController::$events = [];
        NativeController::$work = null;
    }

    public static function boot(): void
    {
        if (defined('SYSTEMPATH')) {
            return;
        }

        require InstalledVersions::getInstallPath('codeigniter4/framework').'/system/Test/bootstrap.php';
        restore_error_handler();
        restore_exception_handler();
    }

    public function request(string $path = 'protected/123'): IncomingRequest
    {
        $request = new IncomingRequest($this->config, new URI('http://localhost/'.$path), null, new UserAgent());
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');

        return $request;
    }

    public function run(IncomingRequest $request, string $permissionAlias = 'permission'): ResponseInterface
    {
        // Reset before routing: unmatched routes never enter either admission filter.
        $this->authentication->clear($request);
        // CI4's service registry, router, filters and response are request-owned.
        Services::reset();
        Factories::reset();
        Events::removeAllListeners();
        config(Feature::class)->oldFilterOrder = false;
        $routing = new Routing();
        $routing->autoRoute = false;
        $routing->useControllerAttributes = true;

        $routes = new RouteCollection(Services::locator(), new Modules(), $routing);
        $routes->setAutoRoute(false);

        $gates = $this->featureAdmission === null ? [$permissionAlias] : $this->featureGates;
        $routes->match(['GET', 'POST'], 'protected/(:num)', $this->handler, [
            'filter'                     => [...$gates, 'validator'],
            FeatureRequirements::class   => $this->featureRequirements,
            PermissionDeclaration::class => $this->declaration
        ]);
        $routes->get('restricted/(:num)', $this->handler, [
            'filter'                     => ['permission', 'validator'],
            PermissionDeclaration::class => new PermissionDeclaration(AdmissionMode::PROTECTED, [
                PermissionName::fromString('AGENT_PROFILE_READ'),
                PermissionName::fromString('ACCOUNT_MANAGE')
            ])
        ]);
        $routes->get('public', static fn(): ResponseInterface => new Response(new App())->setStatusCode(202), [
            'filter'                     => 'permission',
            PermissionDeclaration::class => new PermissionDeclaration(AdmissionMode::PUBLIC)
        ]);
        $config = new FilterConfig();
        $config->aliases = [
            'permission' => NativeFilter::class,
            'validator'  => NativeValidator::class,
            'required'   => NativeRequiredFilter::class
        ];
        if ($this->featureAdmission !== null) {
            NativeFeatureFilter::$delegate = new FeatureFilter(
                static fn() => Services::router(),
                $this->authentication,
                $this->featureAdmission,
                $this->errors
            );
            $config->aliases['feature'] = NativeFeatureFilter::class;
            if (!in_array('permission', $this->featureGates, true)) {
                unset($config->aliases['permission']);
            }
        }

        $config->required = ['before' => [], 'after' => []];
        if ($this->requiredFilters) {
            $config->required = ['before' => ['required'], 'after' => ['required']];
        }

        $config->globals = ['before' => [], 'after' => []];
        $config->methods = [];
        $config->filters = [];

        $response = new Response($this->config);
        $logger = new class (new LoggerConfig(), $this) extends Logger {
            public function __construct(LoggerConfig $config, private readonly NativeApplication $native)
            {
                parent::__construct($config);
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->native->nativeLogs[] = (string) $message;
            }
        };
        Services::injectMock('logger', $logger);
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        Services::injectMock('filters', new Filters($config, $request, $response));
        $app = new SafeCodeIgniter($this->config, $this->errors);
        $app->initialize();
        $app->setContext('web');

        return $app->run($routes, true);
    }
}
