<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\FeatureSubscriber;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Adapter\Http\Symfony\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\Symfony\SelectedController;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
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
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

#[CoversClass(FeatureSubscriber::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(SelectedController::class)]
#[CoversClass(PermissionSubscriber::class)]
final class FeatureSubscriberTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forms(): iterable
    {
        $forms = ['method-string', 'method-array', 'method-object', 'invokable-class', 'invokable-object', 'closure'];
        foreach ($forms as $form) {
            yield $form => [$form];
        }
    }

    #[DataProvider('forms')]
    public function test_feature_only_real_kernel_status_context_fault_and_fresh_state_matrix(string $form): void
    {
        [$features, $permissions, $id] = $this->storage();
        $environment = new AdmissionEnvironment();
        $state = new class {
            public bool $grant = true;

            public bool $valid = true;

            public bool $anonymous = false;

            public ?RuntimeException $fault = null;
        };
        $authority = static fn(): ?RequestAuthority => $state->anonymous ? null : $environment->user(
            $state->grant,
            $state->valid,
            $state->fault,
            $id
        );
        NativeController::$trace = new NativeTrace();
        $controller = match ($form) {
            'method-string' => NativeController::class.'::handle',
            'method-array' => [NativeController::class, 'handle'],
            'method-object' => [new NativeController(), 'handle'],
            'invokable-class' => NativeController::class,
            'invokable-object' => new NativeController(),
            default => static function (Request $request): Response {
                NativeController::$trace->events[] = 'action';
                NativeController::$trace->contexts[] = $request->attributes->get(SecurityContext::class);

                return new Response('', 204);
            }
        };
        $native = $this->native($features, $permissions, $authority, $controller);
        $request = $this->request();
        $this->seed($features, $id, FeatureStatus::OFF);
        $denied = $native->request($request);
        self::assertSame(404, $denied->getStatusCode());
        self::assertSame(['report', 'response'], $native->trace->events);
        self::assertSame('application/json', $denied->headers->get('Content-Type'));
        self::assertStringNotContainsString('rollout', (string) $denied->getContent());
        self::assertSame('preserved', $denied->headers->get('X-Lifecycle'));
        $this->seed($features, $id, FeatureStatus::PREVIEW);
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertInstanceOf(SecurityContext::class, $native->trace->contexts[0]);
        $state->grant = false;
        self::assertSame(404, $native->request($request)->getStatusCode());
        $state->anonymous = true;
        self::assertSame(404, $native->request($request)->getStatusCode());
        $this->seed($features, $id, FeatureStatus::ON);
        $request->attributes->set(SecurityContext::class, $environment->user()->resolve());
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertNull($native->trace->contexts[1]);
        $state->anonymous = false;
        $state->valid = false;
        self::assertSame(401, $native->request($request)->getStatusCode());
        self::assertFalse($request->attributes->has(SecurityContext::class));
        $state->valid = true;
        $state->fault = new RuntimeException('PRIVATE PROVIDER', 404);
        self::assertSame(500, $native->request($request)->getStatusCode());
        $state->fault = null;
        $features->records = [];
        self::assertSame(500, $native->request($request)->getStatusCode());
        foreach (FeatureStatus::cases() as $status) {
            $this->seed($features, PermissionId::generate(), $status);
            self::assertSame(500, $native->request($request)->getStatusCode());
        }

        self::assertNotContains('raw-logger', $native->trace->events);
        self::assertCount(9, $native->trace->reports);
        self::assertNull($native->stack->getCurrentRequest());
        self::assertSame(0, $features->writes);
    }

    public function test_native_validation_arguments_and_is_granted_still_restrict_feature_on(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        $native = $this->native($features, $permissions, static fn(): null => null);
        $invalid = $this->request();
        $invalid->query->set('name', '123');
        self::assertSame(400, $native->request($invalid)->getStatusCode());
        self::assertSame(['construct', 'validation', 'report', 'response'], $native->trace->events);
        $native->trace->events = [];
        $native->trace->nativeGranted = false;
        self::assertSame(403, $native->request($this->request())->getStatusCode());
        self::assertContains('arguments', $native->trace->events);
        self::assertNotContains('action', $native->trace->events);
        $native->trace->nativeGranted = true;
        $native->trace->events = [];
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame([
            'construct', 'validation', 'arguments', 'is-granted:CONSUMER_SCOPE:none',
            'is-granted:CONSUMER_RECORD:123', 'action', 'response'
        ], $native->trace->events);
    }

    public function test_trusted_bindings_ignore_copied_requirements_and_reject_swapped_controllers(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::OFF);
        $native = $this->native($features, $permissions, static fn(): null => null);
        $request = $this->request();
        $request->attributes->set(FeatureRequirements::class, new FeatureRequirements(
            SelectedAction::method(NativeController::class, 'handle')
        ));
        self::assertSame(404, $native->request($request)->getStatusCode());
        $this->seed($features, $id, FeatureStatus::ON);
        $request->attributes->set('_controller', static fn(): Response => new Response('bypass'));
        self::assertSame(500, $native->request($request)->getStatusCode());
        $native->eventDispatcher->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event): void {
            $event->setController(static fn(): Response => new Response('bypass'));
        }, 512);
        $native->trace->events = [];
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(['construct', 'report', 'response'], $native->trace->events);
        $native->routes->get('protected')->setDefault(FeatureRequirements::class, new stdClass());
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        $native->routes->get('protected')->setDefault(FeatureRequirements::class, new FeatureRequirements(
            SelectedAction::invokable(NativeController::class),
            'rollout'
        ));
        self::assertSame(500, $native->request($this->request())->getStatusCode());
    }

    public function test_nested_and_sequential_requests_do_not_inherit_parent_authentication(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::PREVIEW);
        $environment = new AdmissionEnvironment();
        $select = static fn(Request $request): ?RequestAuthority =>
            $request->headers->has('X-Own-Credential') ? $environment->user(permissionId: $id) : null;
        $native = $this->native($features, $permissions, $select);
        $parent = $this->request();
        $parent->headers->set('X-Own-Credential', 'fixture');

        NativeController::$work = function () use ($native, $parent): Response {
            $child = $this->request();
            $child->attributes->set(SecurityContext::class, $parent->attributes->get(SecurityContext::class));
            self::assertSame(404, $native->request($child, HttpKernel::SUB_REQUEST)->getStatusCode());
            self::assertSame($parent, $native->stack->getCurrentRequest());
            NativeController::$work = null;
            $child->headers->set('X-Own-Credential', 'child-fixture');
            self::assertSame(204, $native->request($child, HttpKernel::SUB_REQUEST)->getStatusCode());
            self::assertSame($parent, $native->stack->getCurrentRequest());

            return new Response('', 204);
        };
        self::assertSame(204, $native->request($parent)->getStatusCode());
        self::assertSame(2, $environment->resolutions);
        $parent->headers->remove('X-Own-Credential');
        self::assertSame(404, $native->request($parent)->getStatusCode());
        self::assertFalse($parent->attributes->has(SecurityContext::class));
        self::assertNull($native->stack->getCurrentRequest());
    }

    public function test_combined_gates_share_agent_nonce_and_preserve_public_route_features_in_both_orders(): void
    {
        foreach ([true, false] as $featureFirst) {
            foreach ([AdmissionMode::PUBLIC, AdmissionMode::PROTECTED] as $mode) {
                $environment = new ProfileToolEnvironment();
                $authority = new NativeAgentAuthority($environment);
                $features = new InMemoryFeatureRepository($environment->storage->transaction);
                $permission = $environment->permissionRepository->getByName(
                    PermissionName::fromString('AGENT_PROFILE_READ')
                );
                self::assertNotNull($permission);
                $this->seed($features, $permission->getId(), FeatureStatus::PREVIEW);
                $calls = 0;
                $native = new NativeKernel(
                    static function (Request $request) use ($authority, &$calls): RequestAuthority {
                        ++$calls;

                        return $authority->create($request);
                    },
                    new PermissionDeclaration(
                        $mode,
                        $mode === AdmissionMode::PROTECTED ? [PermissionName::fromString('AGENT_PROFILE_READ')] : []
                    ),
                    features: new FeatureAdmission(new FeatureAvailability(
                        $features,
                        $environment->permissionRepository
                    )),
                    requirements: new FeatureRequirements(
                        SelectedAction::method(NativeController::class, 'handle'),
                        'rollout'
                    ),
                    featureFirst: $featureFirst
                );
                self::assertSame(204, $native->request($this->request())->getStatusCode());
                self::assertSame(1, $calls);
                self::assertSame(1, $environment->nonces->consumptionCalls());
                self::assertInstanceOf(SecurityContext::class, $native->trace->contexts[0]);
                $this->seed($features, $permission->getId(), FeatureStatus::OFF);
                self::assertSame(404, $native->request($this->request())->getStatusCode());
                self::assertSame(2, $calls);
                self::assertSame(2, $environment->nonces->consumptionCalls());
            }
        }
    }

    public function test_feature_on_never_bypasses_composed_permission_refusal(): void
    {
        foreach ([true, false] as $featureFirst) {
            [$features, $permissions, $id] = $this->storage();
            $this->seed($features, $id, FeatureStatus::ON);
            $environment = new AdmissionEnvironment();
            $state = new class {
                public bool $anonymous = false;
            };
            $native = new NativeKernel(
                static function () use ($environment, $id, $state): ?RequestAuthority {
                    if ($state->anonymous) {
                        return null;
                    }

                    return $environment->user(grant: false, permissionId: $id);
                },
                new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('AGENT_PROFILE_READ')]),
                projector: $this->challenge(),
                features: new FeatureAdmission(new FeatureAvailability($features, $permissions)),
                requirements: new FeatureRequirements(
                    SelectedAction::method(NativeController::class, 'handle'),
                    'rollout'
                ),
                featureFirst: $featureFirst
            );
            $denied = $native->request($this->request());
            self::assertSame(403, $denied->getStatusCode());
            self::assertNotContains('construct', $native->trace->events);
            self::assertSame(1, $environment->resolutions);
            $state->anonymous = true;
            $unauthenticated = $native->request($this->request());
            self::assertSame(401, $unauthenticated->getStatusCode());
            self::assertSame('Signature realm="example"', $unauthenticated->headers->get('WWW-Authenticate'));
            self::assertSame([], $native->trace->contexts);
        }
    }

    public function test_empty_feature_declarations_need_no_authentication_and_do_not_activate_permissions(): void
    {
        [$features, $permissions] = $this->storage();
        $native = new NativeKernel(static function (): never {
            self::fail('An unrestricted Feature action must not authenticate.');
        }, null, features: new FeatureAdmission(new FeatureAvailability($features, $permissions)), permissions: false);
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame([], $features->lookups);
    }

    public function test_provider_selection_fault_categories_and_sink_failure_remain_safe(): void
    {
        foreach ([new RuntimeException('PRIVATE'), new AuthenticationRequired('PRIVATE')] as $fault) {
            [$features, $permissions, $id] = $this->storage();
            $this->seed($features, $id, FeatureStatus::ON);
            $native = $this->native($features, $permissions, static function () use ($fault): never {
                throw $fault;
            });
            $native->trace->sinkFails = true;
            $response = $native->request($this->request());
            self::assertSame(500, $response->getStatusCode());
            self::assertStringNotContainsString('PRIVATE', (string) $response->getContent());
            self::assertCount(1, $native->trace->reports);
            self::assertNotContains('raw-logger', $native->trace->events);
            self::assertNotContains('construct', $native->trace->events);
        }
    }

    #[DataProviderExternal(PermissionSubscriberTest::class, 'negotiation')]
    public function test_feature_denial_uses_actual_native_negotiation(?string $accept, bool $xhr, bool $safe): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::OFF);
        $native = $this->native($features, $permissions, static fn(): null => null);
        $request = $this->request();
        $request->headers->remove('Accept');
        if ($accept !== null) {
            $request->headers->set('Accept', $accept);
        }

        if ($xhr) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        $response = $native->request($request);
        self::assertSame($safe ? 404 : 500, $response->getStatusCode());
        self::assertCount($safe ? 1 : 0, $native->trace->reports);
        self::assertSame(!$safe, in_array('raw-logger', $native->trace->events, true));
        self::assertSame(!$safe, in_array('delegated-html', $native->trace->events, true));
        self::assertNotContains('construct', $native->trace->events);
        self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
    }

    #[DataProviderExternal(PermissionSubscriberTest::class, 'terminalFaults')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_feature_denial_encoder_and_render_fallback(int $failures, bool $sinkFails): void
    {
        require __DIR__.'/FaultingEncoder.php';
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::OFF);
        $native = $this->native($features, $permissions, static fn(): null => null);
        EncodingFault::$remaining = $failures;
        $native->trace->sinkFails = $sinkFails;
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertCount(1, $native->trace->reports);
        self::assertNotContains('raw-logger', $native->trace->events);
        self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        self::assertNull($native->stack->getCurrentRequest());
        EncodingFault::$remaining = 0;
        $native->trace->sinkFails = false;
        self::assertSame(404, $native->request($this->request())->getStatusCode());
        self::assertCount(2, $native->trace->reports);
    }

    public function test_feature_denial_projector_failure_is_fixed_safe_500(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::OFF);
        $projector = new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): never
            {
                throw new RuntimeException('PRIVATE PROJECTOR');
            }
        };
        $native = new NativeKernel(
            static fn(): null => null,
            null,
            projector: $projector,
            features: new FeatureAdmission(new FeatureAvailability($features, $permissions)),
            requirements: new FeatureRequirements(SelectedAction::method(NativeController::class, 'handle'), 'rollout'),
            permissions: false
        );
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertCount(1, $native->trace->reports);
        self::assertNotContains('construct', $native->trace->events);
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    public function test_native_effective_ancestry_overrides_repetition_and_route_conjunction(): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        foreach (['inherited', 'child', 'route'] as $name) {
            $features->seed(Feature::reconstitute(
                FeatureId::generate(),
                FeatureName::fromString($name),
                $id,
                FeatureStatus::ON,
                1
            ));
        }

        $native = new NativeKernel(
            static fn(): null => null,
            null,
            FeatureChildController::class.'::handle',
            features: new FeatureAdmission(new FeatureAvailability($features, $permissions)),
            requirements: new FeatureRequirements(
                SelectedAction::method(FeatureChildController::class, 'handle'),
                'route'
            ),
            permissions: false
        );
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertCount(4, $features->lookups);
        $native->routes->get('protected')->setDefault(FeatureRequirements::class, null);
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        $features->records = array_filter(
            $features->records,
            static fn(Feature $feature): bool => $feature->getName()->toString() !== 'inherited'
        );
        $features->seed(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('inherited'),
            $id,
            FeatureStatus::OFF,
            1
        ));
        $native->trace->events = [];
        self::assertSame(404, $native->request($this->request())->getStatusCode());
        self::assertSame(['report', 'response'], $native->trace->events);
    }

    public function test_unsupported_native_forms_and_wrong_route_association_never_skip_features(): void
    {
        [$features, $permissions] = $this->storage();
        $controllers = [null, 'service:handle', ['missing', 'handle'], [NativeController::class, 1], new stdClass()];
        foreach ($controllers as $controller) {
            $native = new NativeKernel(
                static fn(): null => null,
                null,
                $controller,
                features: new FeatureAdmission(new FeatureAvailability($features, $permissions)),
                permissions: false
            );
            self::assertSame(500, $native->request($this->request())->getStatusCode());
            self::assertSame(['report', 'response'], $native->trace->events);
        }

        $native = new NativeKernel(
            static fn(): null => null,
            null,
            features: new FeatureAdmission(new FeatureAvailability($features, $permissions)),
            permissions: false
        );
        $request = $this->request();
        $request->attributes->set('_controller', NativeController::class.'::handle');
        $request->attributes->set('_route', 'public');
        self::assertSame(500, $native->request($request)->getStatusCode());
    }

    /** @return array{InMemoryFeatureRepository, InMemoryPermissionRepository, PermissionId} */
    private function storage(): array
    {
        $unit = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unit);
        $id = PermissionId::generate();
        $permissions->add(Permission::define(
            $id,
            PermissionName::fromString('AGENT_PROFILE_READ'),
            new DateTimeImmutable()
        ));

        return [new InMemoryFeatureRepository($unit), $permissions, $id];
    }

    private function seed(InMemoryFeatureRepository $features, PermissionId $id, FeatureStatus $status): void
    {
        $features->records = [];
        $features->seed(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('rollout'),
            $id,
            $status,
            1
        ));
    }

    /** @param Closure(Request): ?RequestAuthority $authority */
    private function native(
        InMemoryFeatureRepository $features,
        InMemoryPermissionRepository $permissions,
        Closure $authority,
        mixed $controller = NativeController::class.'::handle'
    ): NativeKernel {
        return new NativeKernel(
            $authority,
            null,
            $controller,
            projector: $this->challenge(),
            features: new FeatureAdmission(new FeatureAvailability($features, $permissions)),
            requirements: new FeatureRequirements(new SelectedController()->action($controller), 'rollout'),
            permissions: false
        );
    }

    private function challenge(): SafeHttpProjector
    {
        return new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if ($status === 401) {
                    return new SafeHttpPresentation(
                        metadata: new SafeHttpMetadata(challenge: 'Signature realm="example"')
                    );
                }

                return null;
            }
        };
    }

    private function request(): Request
    {
        $request = Request::create('https://example.test/protected/123?name=Alice');
        $request->headers->set('Accept', 'application/json');

        return $request;
    }
}
