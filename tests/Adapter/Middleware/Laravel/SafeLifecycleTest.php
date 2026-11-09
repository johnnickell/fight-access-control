<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Adapter\Http\Laravel\JSendResponse;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Test\AccessControl\Adapter\Http\OrdinaryFailureCases;
use Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber\EncodingFault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

#[CoversClass(PermissionMiddleware::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SafeLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    /** @return iterable<string, array{string, int, ?string, ?string}> */
    public static function failures(): iterable
    {
        yield 'permission' => ['permission', 403, null, null];
        yield 'missing-challenge' => ['missing-challenge', 500, null, null];
        yield 'native-missing-challenge' => ['native-missing-challenge', 500, null, null];
        yield 'normally-ignored' => ['normally-ignored', 403, null, null];
        yield 'native-challenge' => ['native-challenge', 401, 'WWW-Authenticate', 'Signature realm="example"'];
        yield 'allow' => ['allow', 405, 'Allow', 'GET, POST'];
        yield 'retry' => ['retry', 429, 'Retry-After', '12'];
        foreach (
            ['invalid-challenge', 'invalid-retry', 'invalid-allow', 'missing-allow', 'duplicate-header',
            'unknown-code-cause', 'unsupported-native-subtype', 'unsupported-status', 'exception-hooks'] as $case
        ) {
            yield $case => [$case, 500, null, null];
        }

        yield 'native-forbidden' => ['native-forbidden', 403, null, null];
    }

    #[DataProvider('failures')]
    public function test_native_report_before_render_is_one_redacted_attempt_with_type_policy_and_validated_headers(
        string $case,
        int $status,
        ?string $header,
        ?string $value
    ): void {
        // Build Throwables inside the child; their captured provider stack is not serializable.
        $failure = match ($case) {
            'permission' => new PermissionDenied('PRIVATE'),
            'missing-challenge' => new AuthenticationRequired('PRIVATE'),
            'native-missing-challenge' => new AuthenticationException('PRIVATE'),
            'normally-ignored' => new AuthorizationException('PRIVATE'),
            'native-challenge' => new UnauthorizedHttpException('Signature realm="example"', 'PRIVATE'),
            'allow' => new MethodNotAllowedHttpException(['GET', 'POST'], 'PRIVATE'),
            'retry' => new TooManyRequestsHttpException('12', 'PRIVATE'),
            'invalid-challenge' => new UnauthorizedHttpException("Basic\r\nPRIVATE", 'PRIVATE'),
            'invalid-retry' => new TooManyRequestsHttpException('tomorrow', 'PRIVATE'),
            'invalid-allow' => new HttpException(405, 'PRIVATE', headers: ['Allow' => 'GET,POST']),
            'missing-allow' => new HttpException(405, 'PRIVATE'),
            'duplicate-header' => new HttpException(401, 'PRIVATE', headers: [
                'WWW-Authenticate' => 'Basic realm="one"', 'www-authenticate' => 'Basic realm="two"'
            ]),
            'unknown-code-cause' => new RuntimeException('PRIVATE', 403, new PermissionDenied('PRIVATE')),
            'unsupported-native-subtype' => new class (403, 'PRIVATE') extends HttpException {
            },
            'native-forbidden' => new AccessDeniedHttpException('PRIVATE', headers: ['X-Private' => 'PRIVATE']),
            'unsupported-status' => new HttpException(418, 'PRIVATE'),
            default => new NativeFailure('PRIVATE', 401, new RuntimeException('PRIVATE CAUSE'))
        };
        $native = $this->application();
        $native->trace->failure = $failure;

        $response = $native->request();
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
        self::assertFalse($response->headers->has('X-Private'));
        foreach (['WWW-Authenticate', 'Allow', 'Retry-After'] as $candidate) {
            self::assertSame($candidate === $header ? $value : null, $response->headers->get($candidate));
        }

        self::assertSame(['binding', 'validator', 'action', 'report', 'response'], $native->trace->events);
        self::assertSame([$failure::class], $native->trace->reports);
        self::assertSame(1, $native->trace->deliveries);
        $handler = $native->app->make(ExceptionHandler::class);
        self::assertTrue($handler->shouldReport($failure));
        $handler->report($failure);
        $handler->render(NativeApplication::incoming(), $failure);
        self::assertCount(1, $native->trace->reports);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function negotiation(): iterable
    {
        yield 'json' => ['application/json', '', true];
        yield 'json-quality' => ['application/json;q=1,text/html;q=0.5', '', true];
        yield 'xhr-wildcard' => ['*/*', 'XMLHttpRequest', true];
        yield 'xhr-html-is-not-json' => ['text/html', 'XMLHttpRequest', false];
        yield 'html-preferred' => ['text/html,application/json;q=0.5', '', false];
        yield 'missing' => ['', '', false];
        yield 'html' => ['text/html', '', false];
    }

    #[DataProvider('negotiation')]
    public function test_actual_laravel_negotiation_and_final_response_do_not_claim_html_sanitization(
        string $accept,
        string $xhr,
        bool $safe
    ): void {
        $native = $this->application();
        $native->trace->failure = new NativeFailure('PRIVATE');

        $request = NativeApplication::incoming();
        $request->headers->set('Accept', $accept);
        $request->headers->set('X-Requested-With', $xhr);
        $request->headers->set('X-Debug', 'true');
        self::assertSame($safe, $request->expectsJson());
        $response = $native->request($request);
        self::assertSame($safe ? 500 : 409, $response->getStatusCode());
        self::assertSame(
            $safe ? '{"status":"error","message":"Internal server error."}' : 'Consumer HTML sentinel',
            $response->getContent()
        );
        self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
        self::assertSame(1, $native->trace->deliveries);
        self::assertSame(!$safe, in_array('delegated-exception-render', $native->trace->events, true));
        self::assertNotContains('raw-exception-report', $native->trace->events);
        self::assertNotContains('raw-exception-context', $native->trace->events);
        self::assertNotContains('raw-logger', $native->trace->events);
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        $faults = ['projector', 'object', 'nonfinite', 'utf8', 'depth', 'misapplied-header', 'invalid-header'];
        foreach ($faults as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('faults')]
    public function test_projection_metadata_and_encoding_input_faults_use_actual_native_fallback_without_rereport(
        string $fault
    ): void {
        $projector = new readonly class ($fault) implements SafeHttpProjector {
            public function __construct(private string $fault)
            {
            }

            public function project(Throwable $failure, int $status): SafeHttpPresentation
            {
                $data = ['nested' => true];
                if ($this->fault === 'depth') {
                    for ($i = 0; $i < 501; ++$i) {
                        $data = [$data];
                    }
                }

                return match ($this->fault) {
                    'projector' => throw new RuntimeException('PRIVATE PROJECTOR'),
                    'object' => new SafeHttpPresentation(data: ['value' => new stdClass()]),
                    'nonfinite' => new SafeHttpPresentation(data: ['value' => INF]),
                    'utf8' => new SafeHttpPresentation(message: "\xff"),
                    'depth' => new SafeHttpPresentation(data: $data),
                    'misapplied-header' => new SafeHttpPresentation(metadata: new SafeHttpMetadata(allow: 'GET')),
                    default => new SafeHttpPresentation(metadata: new SafeHttpMetadata(challenge: "Basic\r\nPRIVATE"))
                };
            }
        };
        $native = $this->application($projector);
        $native->trace->failure = new PermissionDenied('PRIVATE');

        $response = $native->request();
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertSame(1, $native->trace->deliveries);
        self::assertCount(1, $native->trace->reports);
        self::assertFalse($response->headers->has('Allow'));
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function test_sink_failure_is_one_unsuccessful_attempt_and_skips_projector_and_native_report_hooks(): void
    {
        $projector = new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): SafeHttpPresentation
            {
                ControllerWithFormRequest::$trace->events[] = 'projector';

                return new SafeHttpPresentation(message: 'Not reached');
            }
        };
        $native = $this->application($projector);
        $native->trace->failure = new NativeFailure('PRIVATE');
        $native->trace->sinkFails = true;

        $response = $native->request();
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        $handler = $native->app->make(ExceptionHandler::class);
        $handler->report($native->trace->failure);
        $handler->render(NativeApplication::incoming(), $native->trace->failure);
        self::assertSame(['binding', 'validator', 'action', 'report', 'response'], $native->trace->events);
        self::assertCount(1, $native->trace->reports);
        self::assertSame(0, $native->trace->deliveries);
    }

    public function test_projector_runs_after_reporting_with_original_type_and_can_supply_consumer_challenge(): void
    {
        $projector = new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): SafeHttpPresentation
            {
                TestCase::assertInstanceOf(AuthenticationRequired::class, $failure);
                TestCase::assertSame(401, $status);
                TestCase::assertSame(1, ControllerWithFormRequest::$trace->deliveries);
                ControllerWithFormRequest::$trace->events[] = 'projector';

                return new SafeHttpPresentation(
                    data: ['message' => 'Sign in.'],
                    metadata: new SafeHttpMetadata(challenge: 'Signature realm="consumer"')
                );
            }
        };
        $native = $this->application($projector);
        $native->trace->failure = new AuthenticationRequired('PRIVATE');

        $response = $native->request();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Signature realm="consumer"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('{"status":"fail","data":{"message":"Sign in."}}', $response->getContent());
        self::assertSame(['binding', 'validator', 'action', 'report', 'projector', 'response'], $native->trace->events);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_actual_jsend_encoder_fault_is_caught_by_common_native_fallback_without_sink_retry(): void
    {
        // Reuse the existing isolated encoder fault fixture; no supplier method is mocked.
        require __DIR__.'/../../Http/Symfony/EventSubscriber/FaultingEncoder.php';
        EncodingFault::$remaining = 1;
        EncodingFault::$calls = 0;
        $native = $this->application();
        $native->trace->failure = new PermissionDenied('PRIVATE');

        $response = $native->request();
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertSame(2, EncodingFault::$calls);
        self::assertSame(1, $native->trace->deliveries);
        self::assertCount(1, $native->trace->reports);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function terminalFaults(): iterable
    {
        yield 'both-encoders' => [2, false];
        yield 'persistent-encoder' => [20, false];
        yield 'failed-sink-and-fallback' => [20, true];
    }

    #[DataProvider('terminalFaults')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_terminal_encoder_failure_stays_inside_common_with_one_original_report_attempt(
        int $failures,
        bool $sinkFails
    ): void {
        require __DIR__.'/../../Http/Symfony/EventSubscriber/FaultingEncoder.php';
        EncodingFault::$remaining = $failures;
        EncodingFault::$calls = 0;
        $native = $this->application();
        $native->trace->failure = new PermissionDenied('PRIVATE ORIGINAL', previous: new RuntimeException('PRIVATE'));
        $native->trace->sinkFails = $sinkFails;

        $response = $native->request();
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Internal server error."}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
        self::assertSame($sinkFails ? 1 : 2, EncodingFault::$calls);
        self::assertSame($sinkFails ? 0 : 1, $native->trace->deliveries);
        self::assertSame([PermissionDenied::class], $native->trace->reports);
        self::assertSame(['binding', 'validator', 'action', 'report', 'response'], $native->trace->events);
        foreach (['WWW-Authenticate', 'Allow', 'Retry-After', 'X-Private'] as $header) {
            self::assertFalse($response->headers->has($header));
        }

        self::assertInstanceOf(JSendResponse::class, $response);
        self::assertSame(['status' => 'error', 'message' => 'Internal server error.'], $response->getData(true));
        ob_start();
        $response->prepare(NativeApplication::incoming())->sendContent();
        $emitted = ob_get_clean();
        self::assertSame('{"status":"error","message":"Internal server error."}', $emitted);
        self::assertSame($sinkFails ? 1 : 2, EncodingFault::$calls);

        EncodingFault::$remaining = 0;
        $native->trace->sinkFails = false;
        $native->trace->failure = new PermissionDenied('PRIVATE NEXT REQUEST');
        self::assertSame(403, $native->request()->getStatusCode());
        self::assertCount(2, $native->trace->reports);
        self::assertSame($sinkFails ? 1 : 2, $native->trace->deliveries);
        $native->trace->failure = null;
        self::assertSame(204, $native->request()->getStatusCode());
        self::assertCount(2, $native->trace->reports);
    }

    public function test_native_application_categories_and_structured_validation_are_safe_by_type(): void
    {
        $native = $this->application();
        foreach (OrdinaryFailureCases::cases() as [$case, $status, $message]) {
            $native->trace->failure = OrdinaryFailureCases::failure($case);
            $before = count($native->trace->reports);
            $response = $native->request();
            self::assertSame($status, $response->getStatusCode(), $case);
            OrdinaryFailureCases::assertBody((string) $response->getContent(), $status, $message);
            self::assertCount($before + 1, $native->trace->reports);
            self::assertSame($native->trace->failure::class, $native->trace->reports[$before]);
            self::assertSame($before + 1, $native->trace->deliveries);
            self::assertNotContains('raw-logger', $native->trace->events);
            self::assertSame('preserved', $response->headers->get('X-Lifecycle'));
        }

        $native = $this->application(OrdinaryFailureCases::validationProjection());
        $native->trace->failure = OrdinaryFailureCases::failure('validation');
        self::assertSame(
            ['status' => 'fail', 'data' => ['fields' => ['name' => ['invalid']]]],
            json_decode((string) $native->request()->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
        self::assertCount(1, $native->trace->reports);
    }

    public function test_projection_cannot_reclassify_unknown_failure_by_status_or_cause(): void
    {
        $projector = new class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): SafeHttpPresentation
            {
                TestCase::assertSame(RuntimeException::class, $failure::class);
                TestCase::assertSame(500, $status);

                return new SafeHttpPresentation(message: 'Public consumer message');
            }
        };
        $native = $this->application($projector);
        $native->trace->failure = new RuntimeException('PRIVATE', 401, new AuthenticationRequired('PRIVATE'));

        $response = $native->request();
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"status":"error","message":"Public consumer message"}', $response->getContent());
        self::assertSame(1, $native->trace->deliveries);
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    private function application(?SafeHttpProjector $projector = null): NativeApplication
    {
        $native = new NativeApplication($projector);
        $route = $native->route();
        $native->protect(
            [[$route, new PermissionDeclaration(AdmissionMode::PUBLIC)]],
            static fn(): ?RequestAuthority => null
        );

        return $native;
    }
}
