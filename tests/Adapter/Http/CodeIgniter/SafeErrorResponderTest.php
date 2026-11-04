<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Fight\AccessControl\Adapter\Http\CodeIgniter\SafeErrorResponder;
use Fight\Common\Adapter\Http\Error\SafeHttpMetadata;
use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ServiceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(SafeErrorResponder::class)]
final class SafeErrorResponderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        NativeApplication::boot();
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        foreach (
            ['missing-challenge', 'bad-challenge', 'misapplied-header', 'object', 'infinite',
            'deep', 'utf8', 'projector', 'primary', 'fallback', 'native-primary', 'native-both'] as $fault
        ) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('faults')]
    public function test_projection_metadata_encoding_and_render_faults_remain_finite_safe_and_report_once(
        string $fault
    ): void {
        $diagnostics = new RecordingDiagnostics();
        $projector = new readonly class ($fault) implements SafeHttpProjector {
            public function __construct(private string $fault)
            {
            }

            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                $data = ['safe' => true];
                if ($this->fault === 'deep') {
                    for ($i = 0; $i < 600; ++$i) {
                        $data = [$data];
                    }
                }

                return match ($this->fault) {
                    'bad-challenge' => new SafeHttpPresentation(metadata: new SafeHttpMetadata(challenge: "X\r\nLeak")),
                    'misapplied-header' => new SafeHttpPresentation(metadata: new SafeHttpMetadata(allow: 'GET')),
                    'object' => new SafeHttpPresentation(data: ['unsafe' => new stdClass()]),
                    'infinite' => new SafeHttpPresentation(data: ['unsafe' => INF]),
                    'deep' => new SafeHttpPresentation(data: $data),
                    'utf8' => new SafeHttpPresentation(message: "\xB1\x31"),
                    'projector' => throw new RuntimeException('PRIVATE PROJECTOR'),
                    default => null
                };
            }
        };
        $calls = 0;
        $responses = static function () use (&$calls, $fault): ResponseInterface {
            ++$calls;
            if ($fault === 'fallback' || $fault === 'primary' && $calls === 1) {
                throw new RuntimeException('PRIVATE RENDER');
            }

            if ($fault === 'native-both' || $fault === 'native-primary' && $calls === 1) {
                return new class (new App()) extends Response {
                    public function setJSON($body, bool $unencoded = false): static
                    {
                        $this->setHeader('X-Partial', 'must-not-escape');
                        throw new RuntimeException('PRIVATE NATIVE RENDER');
                    }
                };
            }

            return new Response(new App());
        };
        $responder = new SafeErrorResponder(new App(), $diagnostics, $projector, $responses);
        $failure = new PermissionDenied('PRIVATE PERMISSION');
        if (in_array($fault, ['missing-challenge', 'bad-challenge'], true)) {
            $failure = new AuthenticationRequired('PRIVATE TOKEN');
        }

        $response = $responder->respond($failure);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(1, $diagnostics->calls);
        self::assertSame(
            ['status' => 'error', 'message' => 'Internal server error.'],
            json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', $response->getHeaderLine('Allow'));
        self::assertSame('', $response->getHeaderLine('X-Partial'));
        self::assertStringNotContainsString('PRIVATE', $response->getBody());
    }

    public function test_deliberate_public_projection_valid_challenge_and_retry_metadata_follow_type_policy(): void
    {
        $diagnostics = new RecordingDiagnostics();
        $projector = new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): SafeHttpPresentation
            {
                $metadata = match ($status) {
                    401 => new SafeHttpMetadata(challenge: 'Signature realm="example"'),
                    503 => new SafeHttpMetadata(retryAfterSeconds: 7),
                    default => null
                };

                return new SafeHttpPresentation(
                    message: 'Public message.',
                    data: ['public' => ['value' => 42]],
                    metadata: $metadata
                );
            }
        };
        $responder = new SafeErrorResponder(new App(), $diagnostics, $projector);
        $response = $responder->respond(new AuthenticationRequired('PRIVATE'));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Signature realm="example"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(
            ['status' => 'fail', 'data' => ['public' => ['value' => 42]]],
            json_decode($response->getBody(), true)
        );
        $response = $responder->respond(new ServiceUnavailable('PRIVATE'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('7', $response->getHeaderLine('Retry-After'));
        $response = $responder->respond(new RuntimeException('PRIVATE', 401, new AuthenticationRequired('PRIVATE')));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Public message.', json_decode($response->getBody(), true)['message']);
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', $response->getHeaderLine('Retry-After'));
        self::assertSame(3, $diagnostics->calls);
    }
}
