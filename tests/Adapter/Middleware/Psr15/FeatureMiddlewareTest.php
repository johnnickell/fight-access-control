<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Adapter\Middleware\Psr15\FeatureMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthentication;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthenticationState;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureUnavailable;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\RateLimitExceeded;
use Fight\Common\Application\Error\Exception\ResourceGone;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\ServiceUnavailable;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\ValidationException;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

#[CoversClass(FeatureMiddleware::class)]
#[CoversClass(FeatureAdmission::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(RequestAuthenticationState::class)]
final class FeatureMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function frameworks(): iterable
    {
        yield 'Slim' => ['slim'];
        yield 'Yii 3' => ['yii'];
    }

    #[DataProvider('frameworks')]
    public function test_feature_only_native_forms_statuses_fresh_reads_and_safe_faults(string $framework): void
    {
        foreach ($framework === 'slim' ? ['method', 'invokable', 'closure'] : ['method', 'closure'] as $form) {
            NativeController::$constructed = 0;
            NativeController::$handled = 0;
            NativeController::$work = null;
            [$features, $permissions, $id] = $this->storage();
            $status = FeatureStatus::OFF;
            $this->seed($features, $id, $status);
            $environment = new AdmissionEnvironment();
            $state = new class {
                public bool $grant = true;

                public bool $valid = true;

                public ?RuntimeException $fault = null;

                public bool $anonymous = false;
            };
            $select = static function () use ($environment, $id, $state): ?RequestAuthority {
                if ($state->anonymous) {
                    return null;
                }

                return $environment->user($state->grant, $state->valid, $state->fault, $id);
            };
            $authentication = new RequestAuthentication($select);
            $native = new NativePipeline($framework, $this->gate($features, $permissions, $authentication), $form);
            $off = $native->request($this->request()->withHeader('Accept', 'text/html'));
            self::assertSame(404, $off->getStatusCode());
            self::assertSame('application/json', $off->getHeaderLine('Content-Type'));
            self::assertStringNotContainsString('rollout', (string) $off->getBody());
            self::assertSame(0, NativeController::$constructed);
            self::assertSame(0, $native->validators);
            $this->seed($features, $id, FeatureStatus::PREVIEW);
            self::assertSame(204, $native->request($this->request())->getStatusCode());
            $state->grant = false;
            self::assertSame(404, $native->request($this->request())->getStatusCode());
            $state->anonymous = true;
            self::assertSame(404, $native->request($this->request())->getStatusCode());
            $this->seed($features, $id, FeatureStatus::ON);
            $forged = $this->request()->withAttribute(SecurityContext::class, $environment->user()->resolve());
            self::assertSame(204, $native->request($forged)->getStatusCode());
            self::assertNull($native->contexts[1]);
            $state->anonymous = false;
            $state->valid = false;
            $refusal = $native->request($this->request());
            self::assertSame(401, $refusal->getStatusCode());
            self::assertSame('Signature realm="example"', $refusal->getHeaderLine('WWW-Authenticate'));
            $state->valid = true;
            $state->fault = new RuntimeException('PRIVATE PROVIDER 404', 404);
            self::assertSame(500, $native->request($this->request())->getStatusCode());
            $state->fault = null;
            $features->records = [];
            self::assertSame(500, $native->request($this->request())->getStatusCode());
            foreach (FeatureStatus::cases() as $state) {
                $this->seed($features, PermissionId::generate(), $state);
                $broken = $native->request($this->request());
                self::assertSame(500, $broken->getStatusCode());
                self::assertStringNotContainsString('rollout', (string) $broken->getBody());
            }

            $this->seed($features, $id, FeatureStatus::ON);
            self::assertSame(204, $native->request($this->request())->getStatusCode());
            self::assertSame(3, $native->validators);
            self::assertSame(3, NativeController::$handled);
            self::assertSame(9, $native->reports);
            self::assertSame(0, $features->writes);
        }
    }

    #[DataProvider('frameworks')]
    public function test_combined_gates_share_actual_agent_authentication_in_either_order(string $framework): void
    {
        foreach ([true, false] as $featureFirst) {
            foreach ([null, AdmissionMode::PUBLIC, AdmissionMode::PROTECTED] as $mode) {
                $environment = new ProfileToolEnvironment();
                $nativeAuthority = new NativeAgentAuthority($environment);
                $calls = 0;
                $authentication = new RequestAuthentication(static function (ServerRequestInterface $request) use (
                    $nativeAuthority,
                    &$calls
                ): RequestAuthority {
                    ++$calls;

                    return $nativeAuthority->create($request);
                });
                [$features] = $this->storage();
                $permission = $environment->permissionRepository->getByName(
                    PermissionName::fromString('AGENT_PROFILE_READ')
                );
                self::assertNotNull($permission);
                $this->seed($features, $permission->getId(), FeatureStatus::PREVIEW);
                $gate = $this->gate($features, $environment->permissionRepository, $authentication);
                $permissionGate = null;
                if ($mode !== null) {
                    $declaration = new PermissionDeclaration(
                        $mode,
                        $mode === AdmissionMode::PROTECTED ? [PermissionName::fromString('AGENT_PROFILE_READ')] : []
                    );
                    $permissionGate = new PermissionMiddleware($declaration, $authentication);
                }

                $native = new NativePipeline($framework, static function (
                    $action,
                    $selected
                ) use (
                    $gate,
                    $permissionGate,
                    $featureFirst
                ): MiddlewareInterface {
                    $feature = $gate($action, $selected);

                    if ($permissionGate === null) {
                        return $feature;
                    }

                    if ($featureFirst) {
                        return new GateChain($feature, $permissionGate);
                    }

                    return new GateChain($permissionGate, $feature);
                });
                NativeController::$work = null;
                self::assertSame(204, $native->request($this->request())->getStatusCode());
                self::assertSame(1, $calls);
                self::assertSame(1, $environment->nonces->consumptionCalls());
                self::assertNotNull($native->contexts[0]);
                self::assertTrue($native->contexts[0]->hasPermission(PermissionName::fromString('AGENT_PROFILE_READ')));
                $this->seed($features, $permission->getId(), FeatureStatus::OFF);
                self::assertSame(404, $native->request($this->request())->getStatusCode());
                self::assertSame(2, $calls);
                self::assertSame(2, $environment->nonces->consumptionCalls());
                self::assertSame(1, $native->validators);
            }
        }
    }

    #[DataProvider('frameworks')]
    public function test_on_does_not_bypass_permission_and_empty_does_not_authenticate(string $framework): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        $authentication = new RequestAuthentication(static fn(): null => null);
        $gate = $this->gate($features, $permissions, $authentication);
        $permission = new PermissionMiddleware(
            new PermissionDeclaration(AdmissionMode::AUTHENTICATED),
            $authentication
        );
        $native = new NativePipeline($framework, static fn($action, $selected): MiddlewareInterface =>
            new GateChain($gate($action, $selected), $permission));
        self::assertSame(401, $native->request($this->request())->getStatusCode());
        self::assertSame(0, $native->validators);
        $never = new RequestAuthentication(static function (): never {
            throw new RuntimeException('No requirement must not select authentication.');
        });
        $emptyGate = $this->gate($features, $permissions, $never, []);
        $public = new PermissionMiddleware(new PermissionDeclaration(AdmissionMode::PUBLIC), $never);
        $empty = new NativePipeline($framework, static fn($action, $selected): MiddlewareInterface =>
            new GateChain($public, $emptyGate($action, $selected)));
        self::assertSame(204, $empty->request($this->request())->getStatusCode());
        self::assertCount(1, $features->lookups);
    }

    #[DataProvider('frameworks')]
    public function test_nested_and_failed_requests_do_not_inherit_copied_context(string $framework): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::PREVIEW);
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $select = static function () use ($environment, $id, &$calls): RequestAuthority {
            ++$calls;

            return $environment->user(grant: $calls !== 2, permissionId: $id);
        };
        $authentication = new RequestAuthentication($select);
        $outer = new NativePipeline($framework, $this->gate($features, $permissions, $authentication));
        $inner = new NativePipeline($framework, $this->gate($features, $permissions, $authentication));
        $outer->beforeWork = function (ServerRequestInterface $request) use ($inner): void {
            self::assertNotNull($request->getAttribute(SecurityContext::class));
            $copy = $this->request()->withAttribute(
                SecurityContext::class,
                $request->getAttribute(SecurityContext::class)
            );
            self::assertSame(404, $inner->request($copy)->getStatusCode());
            throw new RuntimeException('PRIVATE ACTION FAILURE');
        };
        self::assertSame(500, $outer->request($this->request())->getStatusCode());
        $outer->beforeWork = null;
        self::assertSame(204, $outer->request($this->request())->getStatusCode());
        self::assertNotSame($outer->contexts[0], $outer->contexts[1]);
        self::assertSame(3, $environment->resolutions);
        self::assertSame(3, $calls);
        self::assertSame(1, $outer->reports);
    }

    #[DataProvider('frameworks')]
    public function test_effective_inherited_requirements_and_mismatched_target_fault(string $framework): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        $second = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('second'),
            $id,
            FeatureStatus::OFF,
            1
        );
        $features->seed($second);
        $authentication = new RequestAuthentication(static fn(): null => null);
        $native = new NativePipeline($framework, $this->gate($features, $permissions, $authentication), 'inherited');
        NativeController::$constructed = 0;
        NativeController::$handled = 0;
        NativeController::$work = null;
        self::assertSame(404, $native->request($this->request())->getStatusCode());
        self::assertSame(0, NativeController::$constructed);
        self::assertSame(0, $native->validators);
        $features->seed(Feature::reconstitute(
            $second->getId(),
            $second->getName(),
            $id,
            FeatureStatus::ON,
            2
        ));
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame(['rollout', 'second', 'rollout', 'second'], $features->lookups);
        $native->reportHook = static function (): never {
            throw new RuntimeException('PRIVATE SINK FAILURE');
        };
        $features->records = [];
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        self::assertSame(2, $native->reports);

        $mismatched = new NativePipeline($framework, static fn($action): FeatureMiddleware => new FeatureMiddleware(
            new FeatureRequirements($action, 'rollout'),
            static fn(): SelectedAction => SelectedAction::invokable(NativeController::class),
            $authentication,
            new FeatureAdmission(new FeatureAvailability($features, $permissions))
        ));
        self::assertSame(500, $mismatched->request($this->request())->getStatusCode());
        self::assertSame(0, $mismatched->validators);
    }

    #[DataProvider('frameworks')]
    public function test_provider_fault_cannot_impersonate_admission_owned_unavailability(string $framework): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        $environment = new AdmissionEnvironment();
        $authentication = new RequestAuthentication(static fn(): RequestAuthority => $environment->user(
            fault: new FeatureUnavailable('PRIVATE PROVIDER FAILURE'),
            permissionId: $id
        ));
        $native = new NativePipeline($framework, $this->gate($features, $permissions, $authentication));
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $native->validators);
        self::assertSame(1, $native->reports);
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
    }

    #[DataProvider('frameworks')]
    public function test_storage_fault_cannot_impersonate_admission_owned_unavailability(string $framework): void
    {
        [, $permissions] = $this->storage();
        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::once())->method('getByName')->willThrowException(
            new FeatureUnavailable('PRIVATE STORAGE FAILURE')
        );
        $authentication = new RequestAuthentication(static fn(): null => null);
        $factory = static fn($action, $selected): FeatureMiddleware => new FeatureMiddleware(
            new FeatureRequirements($action, 'rollout'),
            $selected,
            $authentication,
            new FeatureAdmission(new FeatureAvailability($features, $permissions))
        );
        $native = new NativePipeline($framework, $factory);
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $native->validators);
        self::assertSame(1, $native->reports);
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
    }

    /** @return iterable<string, array{string, string, Throwable}> */
    public static function dependencyFaults(): iterable
    {
        foreach (['slim', 'yii'] as $framework) {
            foreach (['factory', 'user-provider', 'feature-storage'] as $source) {
                $faults = [
                    new ResourceNotFound('PRIVATE DEPENDENCY FAILURE'),
                    new ResourceGone('PRIVATE DEPENDENCY FAILURE'),
                    new InvalidInput('PRIVATE DEPENDENCY FAILURE'),
                    new PermissionDenied('PRIVATE DEPENDENCY FAILURE'),
                    new StateConflict('PRIVATE DEPENDENCY FAILURE'),
                    new PreconditionFailed('PRIVATE DEPENDENCY FAILURE'),
                    new RuleViolation('PRIVATE DEPENDENCY FAILURE'),
                    new RateLimitExceeded('PRIVATE DEPENDENCY FAILURE'),
                    new ServiceUnavailable('PRIVATE DEPENDENCY FAILURE'),
                    new ValidationException(['private' => ['PRIVATE DEPENDENCY FAILURE']])
                ];
                foreach ($faults as $fault) {
                    yield $framework.' '.$source.' '.$fault::class => [$framework, $source, $fault];
                }
            }
        }
    }

    #[DataProvider('dependencyFaults')]
    public function test_typed_dependency_faults_are_not_declared_admission_outcomes(
        string $framework,
        string $source,
        Throwable $fault
    ): void {
        NativeController::$constructed = 0;
        NativeController::$handled = 0;
        NativeController::$work = null;
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        if ($source === 'feature-storage') {
            $features = $this->createMock(FeatureRepository::class);
            $features->expects(self::once())->method('getByName')->willThrowException($fault);
        }

        $environment = new AdmissionEnvironment();
        $authentication = new RequestAuthentication(static function () use (
            $source,
            $environment,
            $fault
        ): ?RequestAuthority {
            if ($source === 'factory') {
                throw $fault;
            }

            return $source === 'user-provider' ? $environment->user(fault: $fault) : null;
        });
        $factory = static fn($action, $selected): FeatureMiddleware => new FeatureMiddleware(
            new FeatureRequirements($action, 'rollout'),
            $selected,
            $authentication,
            new FeatureAdmission(new FeatureAvailability($features, $permissions))
        );
        $native = new NativePipeline($framework, $factory);
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', $response->getHeaderLine('Retry-After'));
        self::assertSame(0, $native->validators);
        self::assertSame(0, NativeController::$constructed);
        self::assertSame(0, NativeController::$handled);
        self::assertSame(1, $native->reports);
    }

    #[DataProvider('frameworks')]
    public function test_known_authentication_and_downstream_absence_keep_their_categories(string $framework): void
    {
        [$features, $permissions, $id] = $this->storage();
        $this->seed($features, $id, FeatureStatus::ON);
        $authentication = new RequestAuthentication(static function (): never {
            throw new AuthenticationRequired('PRIVATE AUTHENTICATION REFUSAL');
        });
        $native = new NativePipeline($framework, $this->gate($features, $permissions, $authentication));
        $response = $native->request($this->request());
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Signature realm="example"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $native->validators);
        self::assertSame(1, $native->reports);
        $anonymous = new RequestAuthentication(static fn(): null => null);
        $native = new NativePipeline($framework, $this->gate($features, $permissions, $anonymous));
        $native->beforeWork = static function (): never {
            throw new ResourceNotFound('PRIVATE DOWNSTREAM ABSENCE');
        };
        self::assertSame(404, $native->request($this->request())->getStatusCode());
        self::assertSame(1, $native->validators);
        self::assertSame(1, $native->reports);
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

    /**
     * @param list<string> $names
     * @return Closure(SelectedAction, Closure(ServerRequestInterface): SelectedAction): FeatureMiddleware
     */
    private function gate(
        InMemoryFeatureRepository $features,
        InMemoryPermissionRepository $permissions,
        RequestAuthentication $authentication,
        array $names = ['rollout']
    ): Closure {
        return static fn(SelectedAction $action, Closure $selected): FeatureMiddleware => new FeatureMiddleware(
            new FeatureRequirements($action, ...$names),
            $selected,
            $authentication,
            new FeatureAdmission(new FeatureAvailability($features, $permissions))
        );
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', 'https://example.test/protected/123');
    }
}
