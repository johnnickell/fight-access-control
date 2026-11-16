<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Router;
use Composer\Autoload\ClassLoader;
use Config\App;
use DateTimeImmutable;
use Fight\AccessControl\Adapter\Http\CodeIgniter\FeatureFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(FeatureFilter::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(PermissionFilter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IsolatedFeatureFilterTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addPsr4('Config\\', dirname(__DIR__, 4).'/vendor/codeigniter4/framework/app/Config/');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        $faults = ['cli', 'arguments', 'missing-route', 'missing-class', 'remap', 'static', 'metadata', 'mismatch'];
        foreach ($faults as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('faults')]
    public function test_invalid_native_selection_never_authenticates_or_evaluates(string $fault): void
    {
        $router = $this->router();
        $controller = match ($fault) {
            'missing-class' => 'MissingPrivateController',
            'static' => DateTimeImmutable::class,
            'remap' => new class {
                // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- CI4's native hook spelling
                public function _remap(): void
                {
                }
            }::class,
            default => FeatureController::class
        };
        $router->method('controllerName')->willReturn($controller);
        $router->method('methodName')->willReturn($fault === 'static' ? 'createFromFormat' : 'handle');
        $router->method('getMatchedRoute')->willReturn($fault === 'missing-route' ? null : ['route', 'handler']);
        $requirements = 'forged';
        if ($fault === 'mismatch') {
            $requirements = new FeatureRequirements(SelectedAction::closure(static fn(): null => null));
        }

        $router->method('getMatchedRouteOptions')->willReturn([FeatureRequirements::class => $requirements]);
        $request = $fault === 'cli' ? $this->createStub(CLIRequest::class) : $this->createStub(IncomingRequest::class);
        $contexts = new RequestContexts();
        $authentication = new RequestAuthentication(static function (): never {
            self::fail('Invalid target must not authenticate.');
        }, $contexts);
        [$admission] = $this->admission();
        $response = $this->createStub(ResponseInterface::class);
        $failure = null;
        $filter = new FeatureFilter(
            static fn(): Router => $router,
            $authentication,
            $admission,
            $this->errors($response, $failure)
        );

        self::assertSame($response, $filter->before($request, $fault === 'arguments' ? ['forged'] : null));
        self::assertNotInstanceOf(ResourceNotFound::class, $failure);
    }

    public function test_controlled_method_closure_restrictions_and_public_cooperation_keep_context(): void
    {
        [$admission, $features, $id] = $this->admission();
        $router = $this->router();
        $action = static fn(): null => null;
        $router->method('controllerName')->willReturn($action);
        $router->method('getMatchedRoute')->willReturn(['route', $action]);
        $requirements = new FeatureRequirements(SelectedAction::closure($action), 'rollout');
        $router->method('getMatchedRouteOptions')->willReturnCallback(static function () use (&$requirements): array {
            return [FeatureRequirements::class => $requirements,
                PermissionDeclaration::class   => new PermissionDeclaration(AdmissionMode::PUBLIC)];
        });
        $contexts = new RequestContexts();
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $authority = static function () use ($environment, $id, &$calls): RequestAuthority {
            ++$calls;

            return $environment->user(permissionId: $id);
        };
        $authentication = new RequestAuthentication($authority, $contexts);
        $failure = null;
        $response = $this->createStub(ResponseInterface::class);
        $errors = $this->errors($response, $failure);
        $feature = new FeatureFilter(static fn(): Router => $router, $authentication, $admission, $errors);
        $permission = new PermissionFilter(static fn(): Router => $router, $authentication, $errors);
        $request = $this->createStub(IncomingRequest::class);
        self::assertNull($feature->before($request));
        $context = $contexts->get($request);
        self::assertNotNull($context);
        self::assertNull($permission->before($request));
        self::assertSame($context, $contexts->get($request));
        self::assertSame(1, $calls);
        // Repeated gate begins a new attempt even if a consumer reuses the native request/router objects.
        self::assertNull($feature->before($request));
        self::assertSame(2, $calls);
        self::assertNotSame($context, $contexts->get($request));
        $feature->after($request, $response);
        $requirements = null;
        self::assertNull($feature->before($request));
        self::assertNull($contexts->get($request));
        self::assertSame(2, $calls);
        $requirements = new FeatureRequirements(SelectedAction::closure($action), 'rollout');
        $features->records = [];
        self::assertSame($response, $feature->before($request));
        self::assertNotInstanceOf(ResourceNotFound::class, $failure);
    }

    /** @return iterable<string, array{string}> */
    public static function outcomes(): iterable
    {
        foreach (['off', 'anonymous', 'selection', 'provider', 'refusal'] as $outcome) {
            yield $outcome => [$outcome];
        }
    }

    #[DataProvider('outcomes')]
    public function test_only_ordinary_feature_refusal_is_translated_to_not_found(string $outcome): void
    {
        [$admission, $features, $id] = $this->admission();
        if ($outcome === 'off') {
            $features->records = [];
            $features->seed(Feature::reconstitute(
                FeatureId::generate(),
                FeatureName::fromString('rollout'),
                $id,
                FeatureStatus::OFF,
                1
            ));
        }

        $router = $this->router();
        $action = static fn(): null => null;
        $router->method('controllerName')->willReturn($action);
        $router->method('getMatchedRoute')->willReturn(['route', $action]);
        $router->method('getMatchedRouteOptions')->willReturn([
            FeatureRequirements::class => new FeatureRequirements(SelectedAction::closure($action), 'rollout')
        ]);
        $contexts = new RequestContexts();
        $environment = new AdmissionEnvironment();
        $authentication = new RequestAuthentication(static function () use ($outcome, $environment): ?RequestAuthority {
            if ($outcome === 'selection') {
                throw new ResourceNotFound('private selection fault');
            }

            if ($outcome === 'refusal') {
                throw new AuthenticationRequired('private refusal');
            }

            if ($outcome === 'provider') {
                return $environment->user(fault: new ResourceNotFound('private provider fault'));
            }

            return null;
        }, $contexts);
        $response = $this->createStub(ResponseInterface::class);
        $failure = null;
        $filter = new FeatureFilter(
            static fn(): Router => $router,
            $authentication,
            $admission,
            $this->errors($response, $failure)
        );
        $request = $this->createStub(IncomingRequest::class);
        self::assertSame($response, $filter->before($request));
        self::assertSame(in_array($outcome, ['off', 'anonymous'], true), $failure instanceof ResourceNotFound);
        self::assertSame($outcome === 'refusal', $failure instanceof AuthenticationRequired);
        self::assertNull($contexts->get($request));
    }

    public function test_selected_concrete_method_without_feature_metadata_does_not_authenticate(): void
    {
        $router = $this->router();
        $router->method('controllerName')->willReturn(NativeController::class);
        $router->method('methodName')->willReturn('handle');
        $router->method('getMatchedRoute')->willReturn(['route', 'handler']);
        $router->method('getMatchedRouteOptions')->willReturn([]);
        $authentication = new RequestAuthentication(static function (): never {
            self::fail('An ungated Feature action does not authenticate.');
        }, new RequestContexts());
        [$admission] = $this->admission();
        $failure = null;
        $filter = new FeatureFilter(
            static fn(): Router => $router,
            $authentication,
            $admission,
            $this->errors($this->createStub(ResponseInterface::class), $failure)
        );

        self::assertNull($filter->before($this->createStub(IncomingRequest::class)));
    }

    public function test_nested_distinct_request_identity_never_inherits_parent_selection_or_context(): void
    {
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $contexts = new RequestContexts();
        $authentication = new RequestAuthentication(static function () use ($environment, &$calls): ?RequestAuthority {
            return ++$calls === 1 ? $environment->user() : null;
        }, $contexts);
        $router = $this->router();
        $parent = $this->createStub(IncomingRequest::class);
        $child = $this->createStub(IncomingRequest::class);
        $authentication->begin($parent, $router, 'feature');
        $context = $authentication->authority($parent)->resolve();
        $authentication->publish($parent, $context);
        $authentication->begin($child, $this->router(), 'feature');
        self::assertNull($authentication->authority($child));
        self::assertNull($contexts->get($child));
        self::assertSame($context, $contexts->get($parent));
        $authentication->begin($parent, $router, 'permission');
        self::assertSame(
            $context->getAuthenticatedAuthority(),
            $authentication->authority($parent)->resolve()->getAuthenticatedAuthority()
        );
        self::assertSame(2, $calls);
        $authentication->begin($parent, $this->router(), 'feature');
        self::assertNull($contexts->get($parent));
    }

    /** @return array{FeatureAdmission, InMemoryFeatureRepository, PermissionId} */
    private function admission(): array
    {
        $features = new InMemoryFeatureRepository(new InMemoryUnitOfWork());
        $permissions = new InMemoryPermissionRepository();
        $id = PermissionId::generate();
        $permissions->add(Permission::define(
            $id,
            PermissionName::fromString('AGENT_PROFILE_READ'),
            new DateTimeImmutable()
        ));
        $features->seed(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('rollout'),
            $id,
            FeatureStatus::PREVIEW,
            1
        ));

        return [new FeatureAdmission(new FeatureAvailability($features, $permissions)), $features, $id];
    }

    private function router(): Router
    {
        return $this->createStub(Router::class);
    }

    private function errors(ResponseInterface $response, ?Throwable &$failure): SafeErrorResponder
    {
        $diagnostics = new class ($failure) implements SafeHttpDiagnostics {
            public function __construct(public ?Throwable &$failure)
            {
            }

            public function report(Throwable $failure): void
            {
                $this->failure = $failure;
            }
        };
        $response->method('setStatusCode')->willReturnSelf();
        $response->method('setJSON')->willReturnSelf();

        return new SafeErrorResponder(
            $this->createStub(App::class),
            $diagnostics,
            responses: static fn(): ResponseInterface => $response
        );
    }
}
