<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Boot;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Router\Router;
use Config\App;
use Config\Feature;
use Config\Filters;
use Config\Paths;
use Config\Services;
use Fight\AccessControl\Adapter\Http\CodeIgniter\NativeDiagnostic;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeCodeIgniter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeNativeLogger;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class PermissionNativeScenario extends Assert
{
    public static function boot(): void
    {
        // Native bootstrap honors this constant before loading its default writable paths.
        // Each isolated process owns its logs, cache, session and debug output outside dependencies.
        $writable = dirname(__DIR__, 4).'/var/tests/task-00179/native-permission/'.getmypid().'/';
        foreach (['logs', 'cache', 'session', 'debugbar', 'uploads'] as $directory) {
            if (!is_dir($writable.$directory)) {
                mkdir($writable.$directory, 0700, true);
            }
        }

        define('WRITEPATH', $writable);
        $framework = dirname(new ReflectionClass(Router::class)->getFileName(), 3).'/';
        define('ENVIRONMENT', 'production');
        define('FCPATH', $framework.'public/');
        require $framework.'app/Config/Paths.php';
        define('COMPOSER_PATH', getcwd().'/vendor/autoload.php');
        Boot::bootConsole(new Paths());
        // Preserve native required filters; select only this consumer's additional route aliases.
        Services::reset();
        Factories::reset();
        config(Feature::class)->oldFilterOrder = false;
        $filters = config(Filters::class);
        $filters->aliases['permission'] = PermissionShell::class;
        $filters->aliases['validation'] = PermissionValidation::class;
        PermissionController::$trace = [];
    }

    public static function forms(): iterable
    {
        yield 'method allowed' => ['method', true, 200];
        yield 'method denied' => ['method', false, 403];
        yield 'array callback' => ['array', true, 200];
        yield 'invokable' => ['invokable', true, 200];
        yield 'closure explicit public' => ['closure', false, 200];
        yield 'malformed metadata' => ['malformed', true, 500];
        yield 'independent native attribute fault' => ['native', true, 200];
        yield 'native diagnostic sink failure' => ['native-sink', true, 500];
        yield 'mode conflict' => ['conflict', true, 500];
        yield 'override conjunctive' => ['override', true, 403];
        yield 'unknown alias' => ['alias', true, 500];
        yield 'arguments cannot select policy' => ['arguments', true, 500];
        yield 'downstream fault' => ['failure', true, 500];
        yield 'unmatched routing' => ['missing', true, 500];
        yield 'agent direct authority' => ['agent', true, 200];
        yield 'agent missing requirement' => ['agent-denied', true, 403];
        yield 'agent malformed snapshot' => ['agent-snapshot', true, 500];
        yield 'anonymous with valid challenge' => ['anonymous', false, 401];
        yield 'opaque provider fault' => ['provider', true, 500];
        yield 'failed diagnostic sink' => ['sink', false, 500];
        yield 'failed projector' => ['projector', false, 500];
        yield 'encoding failure' => ['encoding', false, 500];
    }

    public function run(
        string $form,
        bool $grant,
        int $status
    ): void {
        self::boot();
        $appConfig = config(App::class);
        $request = new IncomingRequest($appConfig, new URI('http://localhost/selected/17'), null, new UserAgent());
        $request->setMethod('GET');
        Services::injectMock('incomingrequest', $request);
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($appConfig));
        $contexts = new RequestContexts();
        $environment = new AdmissionEnvironment();
        $authentications = 0;
        $reports = [];
        $diagnostics = new class ($reports, $form) implements SafeHttpDiagnostics {
            public function __construct(public array &$reports, private readonly string $form)
            {
            }

            public function report(Throwable $failure): void
            {
                $this->reports[] = $failure instanceof NativeDiagnostic ? $failure : $failure::class;
                if ($this->form === 'sink' || $this->form === 'native-sink') {
                    throw new RuntimeException('private diagnostic failure');
                }
            }
        };
        $projector = new readonly class ($form) implements SafeHttpProjector {
            public function __construct(private string $form)
            {
            }

            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if ($this->form === 'projector') {
                    throw new RuntimeException('private projection failure');
                }

                $metadata = null;
                if ($failure instanceof AuthenticationRequired) {
                    $metadata = new SafeHttpMetadata(challenge: 'Signature realm="fixture"');
                }

                return new SafeHttpPresentation(
                    data: $this->form === 'encoding' ? ['invalid' => INF] : null,
                    metadata: $metadata
                );
            }
        };
        $errors = new SafeErrorResponder($appConfig, $diagnostics, $projector);
        $nativeLogger = new SafeNativeLogger($diagnostics);
        // Supported runtime service override, not the testing-only injectMock logger path.
        Services::override('logger', $nativeLogger);
        self::assertSame($nativeLogger, service('logger'));
        PermissionShell::$filter = new PermissionFilter(
            static fn(): Router => Services::router(),
            new RequestAuthentication(static function () use (
                $environment,
                $grant,
                $form,
                $request,
                &$authentications
            ): ?RequestAuthority {
                ++$authentications;
                if (str_starts_with($form, 'agent')) {
                    $agent = new ProfileToolEnvironment();
                    $agent->request = new SignedAgentRequest(
                        $request->getMethod(),
                        $request->getUri()->getAuthority(),
                        $request->getUri()->getPath(),
                        $request->getUri()->getQuery(),
                        $agent->now(),
                        $agent->request->getNonce(),
                        $agent->original->getCredentialId(),
                        'HMAC-SHA256',
                        'fixture-signature',
                        null,
                        ''
                    );
                    $provider = new CurrentAgentPrincipalProvider(
                        $agent->storage->agentRepository,
                        $form === 'agent-snapshot' ? new InMemoryPermissionRepository() : $agent->permissionRepository,
                        new FixedHmacSharedSecretDecipher('auth-envelope:'),
                        new FixedHmacSignedAgentRequestVerifier($agent->request, 'original-test-secret'),
                        new FixedClock($agent->now()),
                        $agent->nonces,
                        $agent->storage->transaction
                    );

                    return RequestAuthority::agent($provider, $agent->request, $agent->correlationId);
                }

                return $form === 'anonymous' ? null : $environment->user(
                    grant: $grant,
                    fault: $form === 'provider' ? new RuntimeException('private storage fault', 401) : null
                );
            }, $contexts),
            $errors
        );
        $routes = Services::routes();
        $routes->setAutoRoute(false);

        $method = match ($form) {
            'invokable' => '__invoke',
            'malformed' => 'malformed',
            'override', 'agent-denied' => 'handle',
            'failure' => 'failure',
            'native', 'native-sink' => 'nativeFault',
            default => 'inherited'
        };
        $handler = '\\'.PermissionController::class.'::'.$method.'/$1';
        if ($form === 'array') {
            $handler = [PermissionController::class, 'inherited'];
        }

        $options = ['filter' => ['permission', 'validation']];
        if ($form === 'closure') {
            $handler = static function (): string {
                PermissionController::$trace[] = 'action';

                return 'closure admitted';
            };
            $options[PermissionDeclaration::class] = new PermissionDeclaration(AdmissionMode::PUBLIC);
        } elseif ($form === 'conflict') {
            $options[PermissionDeclaration::class] = new PermissionDeclaration(AdmissionMode::PUBLIC);
        } elseif ($form === 'alias') {
            $options['filter'] = ['unknown', 'permission', 'validation'];
        } elseif ($form === 'arguments') {
            $options['filter'] = ['permission:public', 'validation'];
        }

        $routes->get($form === 'missing' ? 'other' : 'selected/(:num)', $handler, $options);
        $app = new SafeCodeIgniter($appConfig, $errors, $nativeLogger);
        $app->initialize();
        $app->setContext('web');
        Services::injectMock('codeigniter', $app);
        $response = $app->run($routes, true);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('private', $response->getBody());
        if ($status === 401) {
            self::assertSame('Signature realm="fixture"', $response->getHeaderLine('WWW-Authenticate'));
        }

        if ($status === 500) {
            self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        }

        if ($status === 200) {
            self::assertContains('validation', PermissionController::$trace);
            self::assertContains('action', PermissionController::$trace);
            self::assertSame($form === 'closure' ? 0 : 1, $authentications);
            if ($form === 'native') {
                self::assertCount(1, $reports);
                self::assertInstanceOf(NativeDiagnostic::class, $reports[0]);
                self::assertSame('private-native-attribute-sentinel', $reports[0]->nativeContext['message']);
                self::assertNull($reports[0]->getPrevious());
            } else {
                self::assertSame([], $reports);
            }
        } else {
            if ($form !== 'failure' && $form !== 'native-sink') {
                self::assertNotContains('validation', PermissionController::$trace);
                self::assertNotContains('construct', PermissionController::$trace);
                self::assertNotContains('action', PermissionController::$trace);
            }

            self::assertCount($form === 'malformed' ? 2 : 1, $reports);
            if ($form === 'malformed') {
                self::assertInstanceOf(NativeDiagnostic::class, $reports[0]);
                self::assertStringContainsString('Failed to instantiate route attribute', $reports[0]->nativeMessage);
            }

            self::assertIsArray(json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        }

        self::assertSame([], glob(WRITEPATH.'logs/*'));
        self::assertFalse($nativeLogger->failed());

        if ($form === 'method' && $grant || $form === 'failure' || $form === 'native-sink') {
            // A consumer resets native services while retaining the injected package filter/error/context owners.
            $contexts->clear($request);
            Services::reset();
            Services::override('logger', $nativeLogger);
            $app->resetForWorkerMode();
            $next = new IncomingRequest($appConfig, new URI('http://localhost/next'), null, new UserAgent());
            $next->setMethod('GET');
            Services::injectMock('incomingrequest', $next);
            Services::injectMock('request', $next);
            Services::injectMock('response', new Response($appConfig));
            Services::injectMock('codeigniter', $app);
            $routes = Services::routes();
            $routes->setAutoRoute(false);
            $routes->get('next', static fn(): string => 'next public response', [
                'filter'                     => ['permission'],
                PermissionDeclaration::class => new PermissionDeclaration(AdmissionMode::PUBLIC)
            ]);
            $later = $app->run($routes, true);
            self::assertSame(200, $later->getStatusCode());
            self::assertStringContainsString('next public response', $later->getBody());
            self::assertStringNotContainsString('private', $later->getBody());
            self::assertSame('', $later->getHeaderLine('WWW-Authenticate'));
            self::assertNull($contexts->get($next));
            self::assertNull($contexts->get($request));
            self::assertSame(1, $authentications);
            self::assertCount($form === 'method' ? 0 : 1, $reports);
        }
    }
}
