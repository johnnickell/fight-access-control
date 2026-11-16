<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthentication;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionRequirements;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Test\AccessControl\Adapter\Http\OrdinaryFailureCases;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use stdClass;
use Throwable;

#[CoversClass(PermissionMiddleware::class)]
#[CoversClass(PermissionAdmission::class)]
#[CoversClass(RequestAuthority::class)]
#[CoversClass(PermissionDeclaration::class)]
final class PermissionMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function frameworks(): iterable
    {
        yield 'Slim' => ['slim'];
        yield 'Yii 3' => ['yii'];
    }

    #[DataProvider('frameworks')]
    public function test_real_user_native_order_forms_and_fresh_contexts(string $framework): void
    {
        foreach (['method', 'closure', 'invokable'] as $form) {
            if ($framework === 'yii' && $form === 'invokable') {
                continue;
            }

            NativeController::$constructed = 0;
            NativeController::$handled = 0;
            $environment = new AdmissionEnvironment();
            $grant = true;
            $valid = true;
            $fault = null;
            $permission = PermissionGate::bind($this->protected(), function () use (
                $environment,
                &$grant,
                &$valid,
                &$fault
            ): RequestAuthority {
                return $environment->user($grant, $valid, $fault);
            });
            $native = new NativePipeline($framework, $permission, $form);
            self::assertSame(204, $native->request($this->request())->getStatusCode());
            self::assertSame(1, $native->validators);
            self::assertSame(1, NativeController::$handled);
            self::assertNotNull($native->contexts[0]);
            $grant = false;
            $denied = $native->request($this->request()->withHeader('Accept', 'text/html'));
            self::assertSame(403, $denied->getStatusCode());
            self::assertSame('application/json', $denied->getHeaderLine('Content-Type'));
            self::assertSame(1, $native->validators);
            self::assertSame(1, NativeController::$handled);
            self::assertSame(1, $native->reports);
            self::assertSame(1, NativeController::$constructed);
            // Do not infer a universal controller-construction contract from pre-work admission.
            $valid = false;
            $refused = $native->request($this->request());
            self::assertSame(401, $refused->getStatusCode());
            self::assertSame('Signature realm="example"', $refused->getHeaderLine('WWW-Authenticate'));
            $valid = true;
            $grant = true;
            $fault = new RuntimeException('PRIVATE STORAGE SENTINEL', 403);
            $broken = $native->request($this->request());
            self::assertSame(500, $broken->getStatusCode());
            self::assertStringNotContainsString('PRIVATE', (string) $broken->getBody());
            self::assertSame(1, $native->validators);
            self::assertSame(3, $native->reports);
            $fault = null;
            self::assertSame(204, $native->request($this->request())->getStatusCode());
            self::assertNotSame($native->contexts[0], $native->contexts[1]);
            self::assertSame(5, $environment->resolutions);
            self::assertSame(202, $native->request($this->request('/other'))->getStatusCode());
            $unmatched = $native->request($this->request('/missing'));
            self::assertSame($framework === 'slim' ? 500 : 404, $unmatched->getStatusCode());
            self::assertSame(2, $native->validators);
        }
    }

    #[DataProvider('frameworks')]
    public function test_real_agent_direct_permissions_native_denial_and_single_nonce(string $framework): void
    {
        NativeController::$handled = 0;
        $environment = new ProfileToolEnvironment();
        $providerCalls = 0;
        $state = new class {
            public bool $brokenSnapshot = false;
        };
        $nativeAuthority = new NativeAgentAuthority($environment);
        $permission = PermissionGate::bind($this->protected(), function (ServerRequestInterface $request) use (
            $nativeAuthority,
            &$providerCalls,
            $state
        ): RequestAuthority {
            ++$providerCalls;

            return $nativeAuthority->create($request, $state->brokenSnapshot);
        });
        $native = new NativePipeline($framework, $permission);
        $router = new InMemoryQueryRouter();
        $router->registerHandler(
            GetAgentProfile::class,
            new GetAgentProfileHandler(
                $environment->storage->agentRepository
            )
        );
        $routingQueryBus = new RoutingQueryBus($router);
        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::once())->method('fetch')->willReturnCallback($routingQueryBus->fetch(...));
        NativeController::$work = static function () use ($queryBus, $environment): void {
            self::assertInstanceOf(
                AgentProfileView::class,
                $queryBus->fetch(new GetAgentProfile(
                    $environment->original->getId()
                ))
            );
        };
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame('GET', $environment->request->getMethod());
        self::assertSame('/protected/123', $environment->request->getPath());
        self::assertSame('example.test', $environment->request->getAuthority());
        self::assertSame(1, $environment->nonces->consumptionCalls());
        // A cooperating handler's snapshot access does not authenticate/replay the signed request.
        self::assertNotNull($native->contexts[0]);
        self::assertTrue($native->contexts[0]->hasPermission(PermissionName::fromString('AGENT_PROFILE_READ')));
        self::assertSame(1, $environment->nonces->consumptionCalls());
        $agent = $environment->storage->agentRepository->all()[0];
        $read = $environment->permissionRepository->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
        self::assertNotNull($read);
        $environment->storage->agentRepository->restoreSnapshot(
            [$agent->revokePermission($read->getId(), $environment->now())]
        );
        $response = $native->request($this->request());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(2, $environment->nonces->consumptionCalls());
        self::assertSame(2, $providerCalls);
        self::assertSame(1, $native->validators);
        self::assertSame(1, NativeController::$handled);
        self::assertSame(1, $native->reports);
        $state->brokenSnapshot = true;
        // A broken complete snapshot is operational, not a fabricated authentication denial.
        self::assertSame(500, $native->request($this->request())->getStatusCode());
        self::assertSame(1, $native->validators);
        self::assertSame(3, $environment->nonces->consumptionCalls());
        self::assertSame(2, $native->reports);
    }

    #[DataProvider('frameworks')]
    public function test_missing_or_malformed_declaration_and_context_fault_do_not_dispatch(string $framework): void
    {
        foreach (['missing', 'malformed', 'context', 'anonymous'] as $case) {
            NativeController::$constructed = 0;
            NativeController::$handled = 0;
            $native = new NativePipeline($framework, PermissionGate::bind(
                $case === 'missing' ? null : $this->protected(),
                static function () use ($case): ?RequestAuthority {
                    if ($case === 'malformed') {
                        new PermissionDeclaration(AdmissionMode::PROTECTED);
                    }

                    if ($case === 'context') {
                        throw new RuntimeException('PRIVATE CONTEXT SENTINEL', 401);
                    }

                    return null;
                }
            ));
            $response = $native->request($this->request());
            self::assertSame($case === 'anonymous' ? 401 : 500, $response->getStatusCode());
            self::assertSame(0, $native->validators);
            self::assertSame(0, NativeController::$handled);
            self::assertSame(1, $native->reports);
            self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        }
    }

    #[DataProvider('frameworks')]
    public function test_public_erases_copied_context_without_resolving_authentication(string $framework): void
    {
        $copied = new AdmissionEnvironment()->user()->resolve();
        $native = new NativePipeline($framework, PermissionGate::bind(
            new PermissionDeclaration(AdmissionMode::PUBLIC),
            static function (): never {
                throw new RuntimeException('Public target must not resolve a provider.');
            }
        ));
        $request = $this->request()->withAttribute(SecurityContext::class, $copied)
            ->withHeader('X-Permissions', 'SUPER_ADMIN')->withAttribute('requirements', []);
        self::assertSame(204, $native->request($request)->getStatusCode());
        self::assertSame([null], $native->contexts);
        self::assertSame(0, $native->reports);
        $denied = new NativePipeline($framework, PermissionGate::bind(
            $this->protected(),
            static fn(): null => null
        ));
        self::assertSame(401, $denied->request($request)->getStatusCode());
        self::assertSame(0, $denied->validators);
    }

    #[DataProvider('frameworks')]
    public function test_nested_dispatch_requires_own_trusted_authority_not_parent_attribute(string $framework): void
    {
        NativeController::$handled = 0;
        $calls = 0;
        $fault = false;
        $selectedRequests = [];
        $nestedStatus = null;
        $parentPreserved = false;
        $incoming = $this->request()->withHeader('X-Request-Probe', 'preserved')
            ->withBody(new StreamFactory()->createStream('signed request body'))
            ->withParsedBody(['value' => 7])->withCookieParams(['session' => 'test-cookie'])
            ->withQueryParams(['b' => '2', 'a' => '1']);
        $authentication = new RequestAuthentication(static function (ServerRequestInterface $request) use (
            &$calls,
            &$fault,
            &$selectedRequests
        ): ?RequestAuthority {
            ++$calls;
            $selectedRequests[] = $request;
            if ($calls % 2 === 1) {
                return new AdmissionEnvironment()->user();
            }

            if ($fault) {
                throw new RuntimeException('PRIVATE NESTED PROVIDER FAILURE');
            }

            return null;
        });
        $inner = new NativePipeline($framework, PermissionGate::bind(
            null,
            $authentication
        ), controller: PermissionNativeNestedRead::class);
        $outer = new NativePipeline($framework, PermissionGate::bind(
            null,
            $authentication
        ), controller: PermissionNativeRead::class);
        $outer->beforeWork = static function (ServerRequestInterface $request) use (
            $inner,
            &$nestedStatus,
            &$parentPreserved
        ): void {
            $parentSnapshot = $request->getAttribute(SecurityContext::class);
            $nestedStatus = $inner->request($request)->getStatusCode();
            $parentPreserved = $parentSnapshot instanceof SecurityContext
                && $parentSnapshot === $request->getAttribute(SecurityContext::class);
        };
        foreach ([false, true, false] as $fault) {
            self::assertSame(204, $outer->request($incoming)->getStatusCode());
            self::assertSame($fault ? 500 : 401, $nestedStatus);
            self::assertTrue($parentPreserved);
            self::assertNull($incoming->getAttribute(SecurityContext::class));
        }

        self::assertSame(6, $calls);
        foreach ($selectedRequests as $request) {
            self::assertNotSame($incoming, $request);
            self::assertNull($request->getAttribute(SecurityContext::class));
            self::assertSame($incoming->getMethod(), $request->getMethod());
            self::assertSame((string) $incoming->getUri(), (string) $request->getUri());
            self::assertSame($incoming->getHeaders(), $request->getHeaders());
            self::assertSame($incoming->getParsedBody(), $request->getParsedBody());
            self::assertSame($incoming->getCookieParams(), $request->getCookieParams());
            self::assertSame($incoming->getQueryParams(), $request->getQueryParams());
            self::assertSame($incoming->getServerParams(), $request->getServerParams());
            self::assertSame($incoming->getUploadedFiles(), $request->getUploadedFiles());
            self::assertSame($incoming->getRequestTarget(), $request->getRequestTarget());
            self::assertSame($incoming->getProtocolVersion(), $request->getProtocolVersion());
            self::assertSame((string) $incoming->getBody(), (string) $request->getBody());
        }

        self::assertSame(0, $inner->validators);
        self::assertSame(3, $inner->reports);
        self::assertSame(3, $outer->validators);
        self::assertSame(3, NativeController::$handled);
        self::assertNotSame($outer->contexts[0], $outer->contexts[1]);
        self::assertNotSame($outer->contexts[1], $outer->contexts[2]);
    }

    #[DataProvider('frameworks')]
    public function test_common_safe_boundary_projection_sink_headers_and_render_fallback(string $framework): void
    {
        $native = new NativePipeline($framework, PermissionGate::bind(
            $this->protected(),
            static fn(): null => null
        ));
        foreach (['missing', 'invalid', 'nonfinite', 'object', 'projection', 'sink', 'render'] as $case) {
            $before = $native->reports;
            $projector = new readonly class ($case) implements SafeHttpProjector {
                public function __construct(private string $case)
                {
                }

                public function project(Throwable $failure, int $status): ?SafeHttpPresentation
                {
                    return match ($this->case) {
                        'missing' => null,
                        'invalid' => new SafeHttpPresentation(
                            metadata: new SafeHttpMetadata(challenge: "Bearer\r\nPRIVATE")
                        ),
                        'nonfinite' => new SafeHttpPresentation(data: ['private' => INF]),
                        'object' => new SafeHttpPresentation(data: ['private' => new stdClass()]),
                        'projection' => throw new RuntimeException('PRIVATE PROJECTOR SENTINEL'),
                        default => new SafeHttpPresentation(metadata: new SafeHttpMetadata(challenge: 'Signature'))
                    };
                }
            };
            $native->reportHook = null;
            if ($case === 'sink') {
                $native->reportHook = static fn(): never => throw new RuntimeException('PRIVATE SINK');
            }

            $responses = null;
            if ($case === 'render') {
                $factory = $this->createMock(ResponseFactoryInterface::class);
                $factory->expects(self::exactly(2))->method('createResponse')->willReturnCallback(
                    static function (int $status): ResponseInterface {
                        if ($status === 401) {
                            throw new RuntimeException('PRIVATE RENDER');
                        }

                        return new Response($status);
                    }
                );
                $responses = new JSendResponseFactory($factory, new StreamFactory());
            }

            $native->errors = $native->boundary($projector, $responses);
            $response = $native->request($this->request());
            self::assertSame(500, $response->getStatusCode(), $case);
            self::assertSame($before + 1, $native->reports, $case);
            self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('{"status":"error","message":"Internal server error."}', (string) $response->getBody());
        }

        self::assertSame(0, $native->validators);
    }

    #[DataProvider('frameworks')]
    public function test_exact_target_association_and_authenticated_only(string $framework): void
    {
        $factory = static fn(): RequestAuthority => new AdmissionEnvironment()->user();
        $native = new NativePipeline(
            $framework,
            PermissionGate::bind($this->protected(), $factory),
            otherPermission: PermissionGate::bind(
                new PermissionDeclaration(AdmissionMode::PROTECTED, [
                PermissionName::fromString('OTHER_PERMISSION')
                ]),
                $factory
            )
        );
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        self::assertSame(403, $native->request($this->request('/second/AGENT_PROFILE_READ'))->getStatusCode());
        self::assertSame(1, $native->validators);
        $authenticated = new NativePipeline($framework, PermissionGate::bind(
            new PermissionDeclaration(AdmissionMode::AUTHENTICATED),
            static fn(): RequestAuthority => new AdmissionEnvironment()->user(false)
        ));
        self::assertSame(204, $authenticated->request($this->request())->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_native_application_categories_and_structured_validation_are_safe_by_type(
        string $framework
    ): void {
        $environment = new AdmissionEnvironment();
        $native = new NativePipeline($framework, PermissionGate::bind(
            $this->protected(),
            static fn(): RequestAuthority => $environment->user()
        ));
        foreach (OrdinaryFailureCases::cases() as [$case, $status, $message]) {
            $failure = OrdinaryFailureCases::failure($case);
            NativeController::$work = static fn(): never => throw $failure;
            $before = $native->reports;
            $response = $native->request($this->request());
            self::assertSame($status, $response->getStatusCode(), $case);
            OrdinaryFailureCases::assertBody((string) $response->getBody(), $status, $message);
            self::assertSame($before + 1, $native->reports);
            self::assertSame($failure::class, $native->reportedTypes[$before]);
            self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        }

        $native->errors = $native->boundary(OrdinaryFailureCases::validationProjection());
        NativeController::$work = static fn(): never => throw OrdinaryFailureCases::failure('validation');
        self::assertSame(
            ['status' => 'fail', 'data' => ['fields' => ['name' => ['invalid']]]],
            json_decode((string) $native->request($this->request())->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
        self::assertSame(11, $environment->resolutions);
        self::assertSame(11, $native->validators);
        self::assertSame(11, $native->reports);
    }

    public function test_unknown_type_remains_500_even_with_a_deliberately_public_projection(): void
    {
        $native = new NativePipeline('slim', PermissionGate::bind($this->protected(), static fn(): never =>
            throw new RuntimeException('PRIVATE AUTH SENTINEL', 401)));
        $native->errors = $native->boundary(new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): SafeHttpPresentation
            {
                TestCase::assertSame(500, $status);

                return new SafeHttpPresentation(message: 'Public service failure.', data: ['reference' => 'safe']);
            }
        });
        $response = $native->request($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Public service failure.', (string) $response->getBody());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        self::assertSame(1, $native->reports);
    }

    public function test_broken_fallback_factory_is_neutral_and_not_recursively_reported(): void
    {
        $native = new NativePipeline('slim', PermissionGate::bind($this->protected(), static fn(): null => null));
        $factory = $this->createMock(ResponseFactoryInterface::class);
        $factory->expects(self::exactly(2))->method('createResponse')->willThrowException(
            new RuntimeException('PRIVATE FACTORY')
        );
        $native->errors = $native->boundary(responses: new JSendResponseFactory($factory, new StreamFactory()));
        try {
            $native->request($this->request());
            self::fail('A broken PSR factory cannot manufacture a response.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Unable to render safe HTTP error.', $runtimeException->getMessage());
            self::assertNull($runtimeException->getPrevious());
            self::assertSame(1, $native->reports);
        }
    }

    #[DataProvider('frameworks')]
    public function test_attribute_only_user_dispatch_and_route_additions_remain_distinct(string $framework): void
    {
        $environment = new AdmissionEnvironment();
        $grant = true;
        $factory = static function () use ($environment, &$grant): RequestAuthority {
            return $environment->user($grant);
        };
        $native = new NativePipeline(
            $framework,
            PermissionGate::bind(null, $factory),
            otherPermission: PermissionGate::bind(new PermissionDeclaration(
                AdmissionMode::PROTECTED,
                [PermissionName::fromString('OTHER_PERMISSION')]
            ), $factory),
            controller: PermissionNativeRead::class
        );
        NativeController::$constructed = 0;
        NativeController::$handled = 0;
        self::assertSame(204, $native->request($this->request())->getStatusCode());
        // Identical native callable, distinct route-bound policy: the extra requirement cannot disappear.
        self::assertSame(403, $native->request($this->request('/second/123'))->getStatusCode());
        $grant = false;
        self::assertSame(403, $native->request($this->request())->getStatusCode());
        self::assertSame(1, $native->validators);
        self::assertSame(1, NativeController::$constructed);
        self::assertSame(1, NativeController::$handled);
        self::assertSame(3, $environment->resolutions);
    }

    #[DataProvider('frameworks')]
    public function test_attribute_inherited_overridden_invokable_agent_conjunction(string $framework): void
    {
        foreach ($framework === 'slim' ? ['method', 'invokable'] : ['method'] as $form) {
            $environment = new ProfileToolEnvironment();
            $authority = new NativeAgentAuthority($environment);
            $native = new NativePipeline(
                $framework,
                PermissionGate::bind(null, $authority->create(...)),
                $form,
                controller: PermissionNativeChild::class
            );
            NativeController::$constructed = 0;
            NativeController::$handled = 0;
            self::assertSame(204, $native->request($this->request())->getStatusCode());
            // Yii inherits the parent method; Slim overrides it without repeating its restriction.
            $agent = $environment->storage->agentRepository->all()[0];
            foreach (['AGENT_PROFILE_UPDATE', 'AGENT_PROFILE_READ'] as $name) {
                $permission = $environment->permissionRepository->getByName(PermissionName::fromString($name));
                self::assertNotNull($permission);
                $environment->storage->agentRepository->restoreSnapshot([
                    $agent->revokePermission($permission->getId(), $environment->now())
                ]);
                self::assertSame(403, $native->request($this->request())->getStatusCode());
            }

            self::assertSame(3, $environment->nonces->consumptionCalls());
            self::assertSame(1, $native->validators);
            self::assertSame(1, NativeController::$constructed);
            self::assertSame(1, NativeController::$handled);
        }
    }

    #[DataProvider('frameworks')]
    public function test_selected_metadata_faults_never_become_refusals_or_resolve_authentication(
        string $framework
    ): void {
        foreach (['mismatch', 'typed', 'malformed'] as $case) {
            $calls = 0;
            $native = new NativePipeline($framework, static function (
                SelectedAction $action
            ) use (
                $case,
                &$calls
            ): PermissionMiddleware {
                return new PermissionMiddleware(
                    new PermissionRequirements($action, new PermissionDeclaration(AdmissionMode::PUBLIC)),
                    static function () use ($case): SelectedAction {
                        if ($case === 'typed') {
                            throw new AuthenticationRequired('PRIVATE TARGET');
                        }

                        if ($case === 'malformed') {
                            return SelectedAction::method(NativeController::class, 'missing');
                        }

                        return SelectedAction::invokable(NativeController::class);
                    },
                    static function () use (&$calls): null {
                        ++$calls;

                        return null;
                    }
                );
            });
            NativeController::$constructed = 0;
            $response = $native->request($this->request());
            self::assertSame(500, $response->getStatusCode(), $case);
            self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('{"status":"error","message":"Internal server error."}', (string) $response->getBody());
            self::assertSame(0, $calls);
            self::assertSame(0, $native->validators);
            self::assertSame(0, NativeController::$constructed);
            self::assertSame(1, $native->reports);
        }
    }

    public function test_conflicting_explicit_modes_reject_during_native_binding_before_authentication(): void
    {
        foreach (['slim', 'yii'] as $framework) {
            foreach ([AdmissionMode::PUBLIC, AdmissionMode::AUTHENTICATED] as $mode) {
                NativeController::$constructed = 0;
                try {
                    new NativePipeline(
                        $framework,
                        PermissionGate::bind(new PermissionDeclaration($mode), static function (): never {
                            self::fail('Invalid composition must not select authentication.');
                        }),
                        controller: PermissionNativeRead::class
                    );
                    self::fail('An explicit mode cannot erase Attributes.');
                } catch (InvalidArgumentException) {
                    self::assertSame(0, NativeController::$constructed);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        NativeController::$work = null;
    }

    private function protected(): PermissionDeclaration
    {
        return new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('AGENT_PROFILE_READ')]);
    }

    private function request(string $path = '/protected/123'): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', 'https://example.test'.$path);
    }
}
