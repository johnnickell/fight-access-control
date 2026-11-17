<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use DateTimeImmutable;
use Fight\AccessControl\Adapter\Http\CodeIgniter\FeatureFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestAuthentication;
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
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(FeatureFilter::class)]
#[CoversClass(RequestAuthentication::class)]
final class FeatureFilterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        NativeApplication::boot();
    }

    /** @return iterable<string, array{string}> */
    public static function forms(): iterable
    {
        foreach (['method-string', 'method-array', 'inherited-invokable', 'closure'] as $form) {
            yield $form => [$form];
        }
    }

    #[DataProvider('forms')]
    public function test_feature_only_native_forms_conjoin_effective_requirements_and_short_circuit(string $form): void
    {
        [$features, $permissions, $id] = $this->storage();
        $handler = match ($form) {
            'method-string' => '\\'.FeatureController::class.'::handle/$1',
            'method-array' => [FeatureController::class, 'handle'],
            'inherited-invokable' => '\\'.FeatureController::class.'::__invoke/$1',
            default => static function (string $id): ResponseInterface {
                NativeController::$events[] = 'action';

                return new Response(new App())->setStatusCode(204)->setHeader('X-Action', $id);
            }
        };
        $action = $handler instanceof Closure ? SelectedAction::closure($handler) : SelectedAction::method(
            FeatureController::class,
            $form === 'inherited-invokable' ? '__invoke' : 'handle'
        );
        $requirements = new FeatureRequirements($action, 'route-gate');
        $names = array_map(
            static fn(FeatureName $name): string => $name->toString(),
            $requirements->getReferences($action)->getNames()
        );
        $native = $this->native($features, $permissions, static fn(): null => null, $handler);
        $native->featureRequirements = $requirements;
        $native->requiredFilters = true;
        foreach ($names as $blocked) {
            $this->seed($features, $id);
            $this->seed($features, $id, FeatureStatus::OFF, $blocked);
            $request = $native->request();
            $request->setHeader('X-Feature', 'ON');
            $native->contexts->set($request, new AdmissionEnvironment()->user()->resolve());
            NativeController::$events = [];
            $response = $native->run($request);
            self::assertSame(404, $response->getStatusCode(), $blocked);
            self::assertSame([], NativeController::$events);
            self::assertSame('preserved', $response->getHeaderLine('X-Required-After'));
            self::assertNull($native->contexts->get($request));
            self::assertSame('Resource not found.', json_decode($response->getBody(), true)['data']['message']);
            self::assertStringNotContainsString($blocked, $response->getBody());
        }

        $this->seed($features, $id);
        NativeController::$events = [];
        $response = $native->run($native->request());
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('123', $response->getHeaderLine('X-Action'));
        $events = ['validator', 'action', 'after'];
        if ($form !== 'closure') {
            $events = ['validator', 'construct', 'action', 'after'];
        }

        self::assertSame($events, NativeController::$events);
        self::assertSame([], $native->nativeLogs);
    }

    public function test_fresh_status_binding_identity_and_provider_faults_never_become_anonymous_denial(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $environment = new AdmissionEnvironment();
        $grant = true;
        $anonymous = false;
        $valid = true;
        $fault = null;
        $native = $this->native($features, $permissions, static function () use (
            $environment,
            $id,
            &$grant,
            &$anonymous,
            &$valid,
            &$fault
        ): ?RequestAuthority {
            return $anonymous ? null : $environment->user($grant, $valid, $fault, $id);
        });
        $request = $native->request();
        foreach (FeatureStatus::cases() as $status) {
            foreach ([true, false] as $authenticated) {
                foreach ([true, false] as $allowed) {
                    $anonymous = !$authenticated;
                    $grant = $allowed;
                    $this->seed($features, $id, $status);
                    $response = $native->run($request);
                    self::assertSame(
                        $status === FeatureStatus::ON
                        || $status === FeatureStatus::PREVIEW && $authenticated && $allowed ? 204 : 404,
                        $response->getStatusCode()
                    );
                }
            }
        }

        $anonymous = false;
        $valid = false;
        $this->seed($features, $id);
        $response = $native->run($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Signature realm="feature"', $response->getHeaderLine('WWW-Authenticate'));
        $valid = true;
        $fault = new RuntimeException('PRIVATE PROVIDER', 404, new ResourceNotFound('PRIVATE CAUSE'));
        self::assertSame(500, $native->run($request)->getStatusCode());
        $fault = null;
        $features->records = [];
        self::assertSame(500, $native->run($request)->getStatusCode());
        foreach (FeatureStatus::cases() as $status) {
            $this->seed($features, PermissionId::generate(), $status);
            $response = $native->run($request);
            self::assertSame(500, $response->getStatusCode());
            self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('', $response->getHeaderLine('X-Action'));
            self::assertStringNotContainsString('PRIVATE', $response->getBody());
            self::assertNull($native->contexts->get($request));
        }

        $this->seed($features, $id);
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertSame([], $native->nativeLogs);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function orders(): iterable
    {
        yield 'feature only' => [['feature']];
        yield 'feature then permission' => [['feature', 'permission']];
        yield 'permission then feature' => [['permission', 'feature']];
    }

    #[DataProvider('orders')]
    public function test_real_agent_authentication_is_once_per_request_and_neither_gate_is_a_grant(array $order): void
    {
        [$features, $permissions] = $this->storage();
        $environment = new ProfileToolEnvironment();
        $permission = $environment->permissionRepository->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
        $id = $permission->getId();
        $permissions = new InMemoryPermissionRepository();
        $permissions->add($permission);
        $this->seed($features, $id, FeatureStatus::PREVIEW);
        $factory = new NativeAgentAuthority($environment);
        $calls = 0;
        $replay = false;
        $native = $this->native($features, $permissions, static function (IncomingRequest $request) use (
            $factory,
            &$calls,
            &$replay
        ): RequestAuthority {
            ++$calls;

            return $factory->create($request, replay: $replay);
        });
        $native->featureGates = $order;
        $native->declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ')
        ]);
        $request = $native->request();
        self::assertSame(204, $native->run($request)->getStatusCode());
        $first = $native->contexts->get($request);
        self::assertNotNull($first);
        self::assertSame(1, $calls);
        self::assertSame(1, $environment->nonces->consumptionCalls());
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertNotSame($first, $native->contexts->get($request));
        self::assertSame(2, $calls);
        self::assertSame(2, $environment->nonces->consumptionCalls());
        $replay = true;
        self::assertSame(401, $native->run($request)->getStatusCode());
        self::assertNull($native->contexts->get($request));
        $replay = false;
        $this->seed($features, $id, FeatureStatus::OFF);
        self::assertSame(404, $native->run($request)->getStatusCode());
        $this->seed($features, $id, FeatureStatus::ON);
        $native->declaration = new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('ACCOUNT_MANAGE')
        ]);
        self::assertSame(
            in_array('permission', $order, true) ? 403 : 204,
            $native->run($request)->getStatusCode()
        );
        self::assertSame([], $native->nativeLogs);
    }

    public function test_public_permission_does_not_erase_feature_context_or_bypass_feature_gate(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $environment = new AdmissionEnvironment();
        $native = $this->native($features, $permissions, static fn(): RequestAuthority => $environment->user(
            permissionId: $id
        ));
        $native->featureGates = ['feature', 'permission'];
        $native->declaration = new PermissionDeclaration(AdmissionMode::PUBLIC);
        $this->seed($features, $id, FeatureStatus::PREVIEW);
        $request = $native->request();
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertNotNull($native->contexts->get($request));
        self::assertSame(1, $environment->resolutions);
        $this->seed($features, $id, FeatureStatus::OFF);
        self::assertSame(404, $native->run($request)->getStatusCode());
    }

    public function test_ungated_feature_action_still_requires_permission_and_distinct_requests_do_not_inherit(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $authority = static function () use ($environment, &$calls): ?RequestAuthority {
            return ++$calls === 1 ? $environment->user() : null;
        };
        $native = $this->native($features, $permissions, $authority, '\\'.NativeController::class.'::handle/$1');
        $native->featureGates = ['permission', 'feature'];
        $native->declaration = new PermissionDeclaration(AdmissionMode::AUTHENTICATED);

        $parent = $native->request();
        self::assertSame(204, $native->run($parent)->getStatusCode());
        $context = $native->contexts->get($parent);
        self::assertNotNull($context);
        $child = $native->request();
        self::assertSame(401, $native->run($child)->getStatusCode());
        self::assertNull($native->contexts->get($child));
        self::assertSame($context, $native->contexts->get($parent));
        self::assertSame([], $features->lookups);
        self::assertSame(2, $calls);
    }

    public function test_reused_request_routing_fault_cannot_retain_an_earlier_admitted_snapshot(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id);
        $environment = new AdmissionEnvironment();
        $native = $this->native($features, $permissions, static fn(): RequestAuthority => $environment->user());
        $request = $native->request();
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertNotNull($native->contexts->get($request));
        $request->setMethod('DELETE');
        self::assertSame(500, $native->run($request)->getStatusCode());
        self::assertNull($native->contexts->get($request));
        self::assertSame([], $native->nativeLogs);
    }

    public function test_route_association_alias_arguments_and_native_faults_fail_safely_before_protected_work(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id);
        $calls = 0;
        $native = $this->native($features, $permissions, static function () use (&$calls): null {
            ++$calls;

            return null;
        });
        foreach (['forged', new FeatureRequirements(SelectedAction::method(FeatureParent::class, 'handle'))] as $bad) {
            $native->featureRequirements = $bad;
            self::assertSame(500, $native->run($native->request())->getStatusCode());
        }

        $native->featureRequirements = null;
        foreach (['feature:rollout', 'missing-alias'] as $alias) {
            $native->featureGates = [$alias];
            self::assertSame(500, $native->run($native->request())->getStatusCode());
        }

        $native->featureGates = ['feature'];
        self::assertSame(500, $native->run($native->request('unmatched'))->getStatusCode());
        self::assertSame([], NativeController::$events);
        self::assertSame(0, $calls);
        self::assertSame([], $native->nativeLogs);
    }

    public function test_feature_loop_owns_downstream_faults_without_duplicate_reporting(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id);
        $native = $this->native($features, $permissions, static fn(): null => null);
        foreach ([new RuntimeException('PRIVATE FAULT', 404), new ResourceNotFound('PRIVATE ABSENCE')] as $failure) {
            NativeController::$work = static function () use ($failure): never {
                echo 'PRIVATE PARTIAL BODY';
                throw $failure;
            };
            $request = $native->request();
            $request->setHeader('Accept', 'text/html');
            $before = count($native->reports);
            $response = $native->run($request);
            self::assertSame($failure instanceof ResourceNotFound ? 404 : 500, $response->getStatusCode());
            self::assertStringNotContainsString('PRIVATE', $response->getBody());
            self::assertIsArray(json_decode($response->getBody(), true));
            self::assertSame('', $response->getHeaderLine('X-Action'));
            self::assertCount($before + 1, $native->reports);
            self::assertSame([], $native->nativeLogs);
        }

        $native->sinkFails = true;
        self::assertSame(500, $native->run($native->request())->getStatusCode());
    }

    /** @return array{InMemoryFeatureRepository, InMemoryPermissionRepository, PermissionId} */
    private function storage(): array
    {
        $permissions = new InMemoryPermissionRepository();
        $id = PermissionId::generate();
        $permissions->add(Permission::define(
            $id,
            PermissionName::fromString('AGENT_PROFILE_READ'),
            new DateTimeImmutable()
        ));

        return [new InMemoryFeatureRepository(new InMemoryUnitOfWork()), $permissions, $id];
    }

    private function seed(
        InMemoryFeatureRepository $features,
        PermissionId $id,
        FeatureStatus $status = FeatureStatus::ON,
        ?string $only = null
    ): void {
        $names = $only === null ? ['rollout', 'parent-gate', 'child-gate', 'method-gate', 'route-gate'] : [$only];
        foreach ($names as $name) {
            foreach ($features->records as $key => $record) {
                if ($record->getName()->toString() === $name) {
                    unset($features->records[$key]);
                }
            }

            $features->seed(Feature::reconstitute(
                FeatureId::generate(),
                FeatureName::fromString($name),
                $id,
                $status,
                1
            ));
        }
    }

    /** @param Closure(IncomingRequest): ?RequestAuthority $authority */
    private function native(
        InMemoryFeatureRepository $features,
        InMemoryPermissionRepository $permissions,
        Closure $authority,
        mixed $handler = '\\'.FeatureController::class.'::handle/$1'
    ): NativeApplication {
        $projector = new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                return $status === 401 ? new SafeHttpPresentation(
                    metadata: new SafeHttpMetadata(challenge: 'Signature realm="feature"')
                ) : null;
            }
        };
        $native = new NativeApplication($authority, null, $handler, $projector);
        $native->featureAdmission = new FeatureAdmission(new FeatureAvailability($features, $permissions));

        return $native;
    }
}
