<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Fight\AccessControl\Adapter\Http\CodeIgniter\PermissionFilter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\RequestContexts;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeCodeIgniter;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Test\AccessControl\Adapter\Http\OrdinaryFailureCases;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(PermissionFilter::class)]
#[CoversClass(RequestContexts::class)]
#[CoversClass(SafeErrorResponder::class)]
#[CoversClass(SafeCodeIgniter::class)]
final class PermissionFilterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        NativeApplication::boot();
    }

    /** @return iterable<string, array{string}> */
    public static function forms(): iterable
    {
        foreach (['method-string', 'method-array', 'invokable', 'closure'] as $form) {
            yield $form => [$form];
        }
    }

    #[DataProvider('forms')]
    public function test_native_filter_short_circuits_before_validation_construction_and_action(string $form): void
    {
        $environment = new AdmissionEnvironment();
        $grant = false;
        $handler = match ($form) {
            'method-string' => '\\'.NativeController::class.'::handle/$1',
            'method-array' => [NativeController::class, 'handle'],
            'invokable' => '\\'.NativeController::class.'::__invoke/$1',
            default => static function (string $id): ResponseInterface {
                NativeController::$events[] = 'action';

                return new Response(new App())->setStatusCode(204)->setHeader('X-Action', $id);
            }
        };
        $native = new NativeApplication(
            static function () use ($environment, &$grant): RequestAuthority {
                return $environment->user($grant);
            },
            $this->protected(),
            $handler
        );
        $request = $native->request();
        $request->setHeader('X-Permissions', 'AGENT_PROFILE_READ');
        $request->setHeader('X-Role', 'ROLE_SUPER_ADMIN');

        $native->contexts->set($request, $environment->user()->resolve());
        $beforeResolutions = $environment->resolutions;
        $denied = $native->run($request);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame([], NativeController::$events);
        self::assertNull($native->contexts->get($request));
        self::assertCount(1, $native->reports);
        $grant = true;
        $admitted = $native->run($request);
        self::assertSame(204, $admitted->getStatusCode());
        self::assertSame('123', $admitted->getHeaderLine('X-Action'));
        self::assertSame(
            $form === 'closure' ? ['validator', 'action', 'after'] : ['validator', 'construct', 'action', 'after'],
            NativeController::$events
        );
        self::assertNotNull($native->contexts->get($request));
        self::assertSame($beforeResolutions + 2, $environment->resolutions);
        self::assertSame([], $native->nativeLogs);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'missing' => [null];
        yield 'raw' => ['AGENT_PROFILE_READ'];
        yield 'ambiguous' => [[new PermissionDeclaration(AdmissionMode::PUBLIC), new stdClass()]];
        yield 'foreign' => [new stdClass()];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_invalid_matched_metadata_fails_before_provider_or_protected_work(mixed $declaration): void
    {
        $calls = 0;
        $native = new NativeApplication(static function () use (&$calls): ?RequestAuthority {
            ++$calls;

            return null;
        }, $declaration);
        $response = $native->run($native->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $calls);
        self::assertSame([], NativeController::$events);
        self::assertSame([], $native->nativeLogs);
        self::assertCount(1, $native->reports);
    }

    public function test_filter_arguments_and_alias_configuration_do_not_select_public_policy(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(static fn(): RequestAuthority => $environment->user(), $this->protected());
        self::assertSame(500, $native->run($native->request(), 'permission:public')->getStatusCode());
        self::assertSame(0, $environment->resolutions);
        self::assertSame([], NativeController::$events);
        self::assertSame(500, $native->run($native->request(), 'missing-alias')->getStatusCode());
        self::assertCount(2, $native->reports);
        self::assertSame([], $native->nativeLogs);
    }

    public function test_public_authenticated_and_same_callback_routes_have_deliberate_distinct_requirements(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(
            static fn(): RequestAuthority => $environment->user(false),
            new PermissionDeclaration(AdmissionMode::AUTHENTICATED)
        );
        $request = $native->request();
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertNotNull($native->contexts->get($request));
        $public = $native->request('public');
        $native->contexts->set($public, $native->contexts->get($request));
        self::assertSame(202, $native->run($public)->getStatusCode());
        self::assertNull($native->contexts->get($public));
        self::assertSame(1, $environment->resolutions);
        self::assertSame(403, $native->run($native->request('restricted/123'))->getStatusCode());
        self::assertSame(2, $environment->resolutions);
    }

    public function test_reused_filter_and_request_get_fresh_authority_after_refusal_and_storage_fault(): void
    {
        $environment = new AdmissionEnvironment();
        $valid = true;
        $fault = null;
        $native = new NativeApplication(static function () use ($environment, &$valid, &$fault): RequestAuthority {
            return $environment->user(valid: $valid, fault: $fault);
        }, $this->protected(), projector: $this->challenge());
        $request = $native->request();
        self::assertSame(204, $native->run($request)->getStatusCode());
        $first = $native->contexts->get($request);
        $valid = false;
        $response = $native->run($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Signature realm="example"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertNull($native->contexts->get($request));
        $valid = true;
        $fault = new RuntimeException('PRIVATE STORAGE', 401, new AuthenticationRequired('PRIVATE CAUSE'));
        $response = $native->run($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', $response->getHeaderLine('X-Action'));
        self::assertStringNotContainsString('PRIVATE', $response->getBody());
        self::assertNull($native->contexts->get($request));
        $fault = null;
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertNotSame($first, $native->contexts->get($request));
        self::assertSame(4, $environment->resolutions);
        self::assertCount(2, $native->reports);
        self::assertSame([], $native->nativeLogs);
    }

    /** @return iterable<string, array{Throwable}> */
    public static function downstreamFailures(): iterable
    {
        yield 'permission' => [new PermissionDenied('PRIVATE DENIAL')];
        yield 'unknown-with-code-cause' => [new RuntimeException(
            'PRIVATE STORAGE',
            403,
            new PermissionDenied('PRIVATE CAUSE')
        )];
        yield 'native-404' => [PageNotFoundException::forPageNotFound('PRIVATE PATH')];
    }

    #[DataProvider('downstreamFailures')]
    public function test_loop_intercepts_downstream_failures_before_native_formatters_and_loggers(
        Throwable $fault
    ): void {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(static fn(): RequestAuthority => $environment->user(), $this->protected());
        NativeController::$work = static function () use ($fault): never {
            echo 'PRIVATE PARTIAL OUTPUT';
            throw $fault;
        };
        $response = $native->run($native->request());
        self::assertSame($fault instanceof PermissionDenied ? 403 : 500, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', $response->getBody());
        self::assertSame('application/json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertCount(1, $native->reports);
        self::assertSame(['type' => $fault::class], $native->reports[0]);
        self::assertSame([], $native->nativeLogs);
        self::assertSame(['validator', 'construct', 'action'], NativeController::$events);
    }

    public function test_native_application_categories_and_structured_validation_are_safe_by_type(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(static fn(): RequestAuthority => $environment->user(), $this->protected());
        foreach (OrdinaryFailureCases::cases() as [$case, $status, $message]) {
            $failure = OrdinaryFailureCases::failure($case);
            NativeController::$work = static fn(): never => throw $failure;
            $before = count($native->reports);
            $response = $native->run($native->request());
            self::assertSame($status, $response->getStatusCode(), $case);
            OrdinaryFailureCases::assertBody($response->getBody(), $status, $message);
            self::assertCount($before + 1, $native->reports);
            self::assertSame(['type' => $failure::class], $native->reports[$before]);
            self::assertSame([], $native->nativeLogs);
            self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        }

        $native = new NativeApplication(
            static fn(): RequestAuthority => $environment->user(),
            $this->protected(),
            projector: OrdinaryFailureCases::validationProjection()
        );
        NativeController::$work = static fn(): never => throw OrdinaryFailureCases::failure('validation');
        self::assertSame(
            ['status' => 'fail', 'data' => ['fields' => ['name' => ['invalid']]]],
            json_decode($native->run($native->request())->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
        self::assertSame(11, $environment->resolutions);
        self::assertCount(1, $native->reports);
    }

    public function test_native_validation_and_routing_failures_are_not_public_fallback_or_raw_errors(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(static fn(): RequestAuthority => $environment->user(), $this->protected());
        $request = $native->request();
        $request->setHeader('X-Invalid', '1');
        self::assertSame(400, $native->run($request)->getStatusCode());
        self::assertSame(['validator'], NativeController::$events);
        NativeController::$events = [];
        self::assertSame(500, $native->run($native->request('missing'))->getStatusCode());
        $request = $native->request();
        $request->setMethod('DELETE');
        self::assertSame(500, $native->run($request)->getStatusCode());
        self::assertSame([], NativeController::$events);
        self::assertSame(1, $environment->resolutions);
        self::assertCount(3, $native->reports);
        self::assertSame([], $native->nativeLogs);
    }

    public function test_missing_selected_authority_and_factory_faults_are_distinct(): void
    {
        $native = new NativeApplication(
            static fn(): ?RequestAuthority => null,
            $this->protected(),
            projector: $this->challenge()
        );
        self::assertSame(401, $native->run($native->request())->getStatusCode());
        $native = new NativeApplication(static function (): never {
            throw new RuntimeException('PRIVATE FACTORY', 401);
        }, $this->protected());
        self::assertSame(500, $native->run($native->request())->getStatusCode());
        self::assertSame([], NativeController::$events);
        self::assertCount(1, $native->reports);
    }

    public function test_unmatched_router_direct_filter_call_cannot_use_stale_metadata(): void
    {
        $native = new NativeApplication(static fn(): ?RequestAuthority => null, $this->protected());
        Services::reset();
        $response = $native->filter->before($native->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $native->reports);
    }

    public function test_actual_agent_direct_grants_nonce_and_snapshot_faults_remain_inward(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $broken = false;
        $replay = false;
        $native = new NativeApplication(static function (IncomingRequest $request) use (
            $authority,
            &$broken,
            &$replay
        ): RequestAuthority {
            return $authority->create($request, $broken, $replay);
        }, $this->protected(), projector: $this->challenge());
        $request = $native->request('protected/123?source=native');
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertSame('GET', $environment->request->getMethod());
        self::assertSame('localhost', $environment->request->getAuthority());
        self::assertSame('/protected/123', $environment->request->getPath());
        self::assertSame('source=native', $environment->request->getNormalizedQuery());
        $context = $native->contexts->get($request);
        self::assertTrue($context->hasPermission(PermissionName::fromString('AGENT_PROFILE_READ')));
        self::assertSame(1, $environment->nonces->consumptionCalls());
        $replay = true;
        self::assertSame(401, $native->run($request)->getStatusCode());
        $replay = false;
        $agent = $environment->storage->agentRepository->all()[0];
        $permission = $environment->permissionRepository->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
        self::assertNotNull($permission);
        $environment->storage->agentRepository->restoreSnapshot([
            $agent->revokePermission($permission->getId(), $environment->now())
        ]);
        self::assertSame(403, $native->run($request)->getStatusCode());
        self::assertNull($native->contexts->get($request));
        $broken = true;
        self::assertSame(500, $native->run($request)->getStatusCode());
        self::assertCount(3, $native->reports);
        self::assertSame([], $native->nativeLogs);
        self::assertTrue($context->hasPermission(PermissionName::fromString('AGENT_PROFILE_READ')));
    }

    public function test_distinct_request_contexts_never_implicitly_propagate_parent_authority(): void
    {
        $environment = new AdmissionEnvironment();
        $calls = 0;
        $native = new NativeApplication(static function () use ($environment, &$calls): ?RequestAuthority {
            return ++$calls === 1 ? $environment->user() : null;
        }, $this->protected(), projector: $this->challenge());
        $parent = $native->request();
        self::assertSame(204, $native->run($parent)->getStatusCode());
        $context = $native->contexts->get($parent);
        $child = $native->request();
        self::assertNull($native->contexts->get($child));
        self::assertSame(401, $native->run($child)->getStatusCode());
        self::assertNull($native->contexts->get($child));
        self::assertSame($context, $native->contexts->get($parent));
    }

    public function test_selected_path_is_json_even_for_html_accept_and_sink_failure_is_not_retried(): void
    {
        $native = new NativeApplication(
            static fn(): ?RequestAuthority => null,
            $this->protected(),
            projector: $this->challenge()
        );
        $request = $native->request();
        $request->setHeader('Accept', 'text/html');
        self::assertSame(401, $native->run($request)->getStatusCode());
        $native->sinkFails = true;
        $response = $native->run($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal server error.', json_decode($response->getBody(), true)['message']);
        self::assertCount(2, $native->reports);
        self::assertSame([], $native->nativeLogs);
    }

    public function test_native_controller_attributes_and_after_filters_are_not_bypassed(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(
            static fn(): RequestAuthority => $environment->user(),
            $this->protected(),
            '\\'.NativeController::class.'::restricted/$1'
        );
        self::assertSame(500, $native->run($native->request())->getStatusCode());
        self::assertSame(['validator'], NativeController::$events);
        self::assertSame(1, $environment->resolutions);
        $native->handler = '\\'.NativeController::class.'::handle/$1';
        NativeController::$events = [];
        $request = $native->request();
        $request->setHeader('X-After-Fault', '1');

        $response = $native->run($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-Action'));
        self::assertSame(['validator', 'construct', 'action', 'after'], NativeController::$events);
        self::assertCount(2, $native->reports);
        self::assertSame([], $native->nativeLogs);
        self::assertSame(204, $native->run($native->request())->getStatusCode());
    }

    public function test_non_http_filter_entry_is_not_an_authentication_or_route_fallback(): void
    {
        $native = new NativeApplication(static fn(): ?RequestAuthority => null, $this->protected());
        $response = $native->filter->before(new CLIRequest($native->config));
        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $native->reports);
    }

    public function test_agent_request_binds_actual_post_body_query_and_authority(): void
    {
        $environment = new ProfileToolEnvironment();
        $authority = new NativeAgentAuthority($environment);
        $native = new NativeApplication($authority->create(...), $this->protected());
        $request = $native->request('protected/456?source=native');
        $request->setMethod('POST');
        $request->setBody('native-body');
        self::assertSame(204, $native->run($request)->getStatusCode());
        self::assertSame('POST', $environment->request->getMethod());
        self::assertSame('/protected/456', $environment->request->getPath());
        self::assertSame('localhost', $environment->request->getAuthority());
        self::assertSame('source=native', $environment->request->getNormalizedQuery());
        self::assertSame('native-body', $environment->request->getBody());
        self::assertSame(hash('sha256', 'native-body'), $environment->request->getBodyDigest());
        self::assertSame(1, $environment->nonces->consumptionCalls());
    }

    public function test_required_native_guards_are_preserved_and_outside_failures_are_not_claimed_safe(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(static fn(): RequestAuthority => $environment->user(false), $this->protected());
        $native->requiredFilters = true;

        $request = $native->request();
        $request->setHeader('X-Required-Refusal', '1');

        $response = $native->run($request);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('preserved', $response->getHeaderLine('X-Required-After'));
        self::assertSame(0, $environment->resolutions);
        self::assertCount(0, $native->reports);
        self::assertSame([], NativeController::$events);
        $response = $native->run($native->request());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('preserved', $response->getHeaderLine('X-Required-After'));
        self::assertCount(1, $native->reports);
        $request = $native->request();
        $request->setHeader('X-Required-Fault', '1');
        try {
            $native->run($request);
            self::fail('A required-before failure is outside the selected boundary.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Outside selected handleRequest boundary.', $runtimeException->getMessage());
        }

        self::assertCount(1, $native->reports);
    }

    public function test_routes_sharing_one_controller_keep_all_conjunctive_requirements(): void
    {
        $environment = new AdmissionEnvironment();
        $native = new NativeApplication(static fn(): RequestAuthority => $environment->user(), $this->protected());
        self::assertSame(204, $native->run($native->request())->getStatusCode());
        NativeController::$events = [];
        self::assertSame(403, $native->run($native->request('restricted/123'))->getStatusCode());
        self::assertSame([], NativeController::$events);
        self::assertSame(2, $environment->resolutions);
        self::assertCount(1, $native->reports);
    }

    private function protected(): PermissionDeclaration
    {
        return new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('AGENT_PROFILE_READ')]);
    }

    private function challenge(): SafeHttpProjector
    {
        return new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if ($status !== 401) {
                    return null;
                }

                return new SafeHttpPresentation(metadata: new SafeHttpMetadata(challenge: 'Signature realm="example"'));
            }
        };
    }
}
