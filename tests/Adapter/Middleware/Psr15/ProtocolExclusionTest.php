<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Http\Mcp\McpResponseFactory;
use Fight\Common\Adapter\Http\OAuth\OAuthResponseFactory;
use Fight\Common\Application\Auth\OAuth\OAuthFailure;
use Fight\Common\Application\Auth\OAuth\OAuthResourceMetadata;
use Fight\Common\Application\Auth\OAuth\OAuthScopeSet;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpJsonResponse;
use Fight\Common\Application\Mcp\McpProtocolException;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Exception\LookupException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use stdClass;
use Throwable;

#[CoversClass(PermissionMiddleware::class)]
final class ProtocolExclusionTest extends TestCase
{
    #[DataProviderExternal(PermissionMiddlewareTest::class, 'frameworks')]
    public function test_native_protocol_routes_keep_mcp_exact_class_failure_and_concealment(string $framework): void
    {
        foreach (['known', 'subclass', 'denied'] as $case) {
            $environment = new ProfileToolEnvironment();
            $queries = $this->createMock(QueryBus::class);
            if ($case === 'denied') {
                $read = $environment->permissions->getByName(PermissionName::fromString('AGENT_PROFILE_READ'));
                self::assertNotNull($read);
                $agent = $environment->storage->agents->all()[0];
                $environment->storage->agents->restoreSnapshot([
                    $agent->revokePermission($read->getId(), $environment->now())
                ]);
                $queries->expects(self::never())->method('fetch');
            } else {
                $failure = new LookupException('PRIVATE MCP SENTINEL');
                if ($case === 'subclass') {
                    $failure = new class ('PRIVATE MCP SENTINEL') extends LookupException {
                    };
                }

                $queries->expects(self::once())->method('fetch')->willThrowException($failure);
            }

            $invoker = $environment->invoker(queries: $queries);
            $diagnostics = $this->createMock(McpDiagnostics::class);
            $diagnostics->expects($case === 'subclass' ? self::once() : self::never())->method('record');
            $responses = new McpResponseFactory(new ResponseFactory(), new StreamFactory(), $diagnostics);
            $native = new NativePipeline($framework, new PermissionMiddleware(
                new PermissionDeclaration(AdmissionMode::AUTHENTICATED),
                static fn() => throw new RuntimeException('Excluded ordinary collaborator must not resolve.')
            ), protocol: static function () use ($invoker, $responses): ResponseInterface {
                try {
                    return $responses->fromResponse(McpJsonResponse::success(
                        7,
                        $invoker->invoke('agent.profile.read', new stdClass())
                    ));
                } catch (McpProtocolException $failure) {
                    return $responses->fromResponse(McpJsonResponse::error(7, $failure->protocolError()));
                } catch (Throwable $failure) {
                    return $responses->failure($failure, 7);
                }
            });
            $response = $native->request(new ServerRequestFactory()->createServerRequest(
                'GET',
                'https://example.test/protocol'
            ));
            $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('2.0', $data['jsonrpc']);
            self::assertSame(7, $data['id']);
            self::assertArrayNotHasKey('status', $data);
            self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
            self::assertSame(0, $native->reports);
            self::assertSame(0, $native->validators);
            if ($case === 'known') {
                self::assertSame(200, $response->getStatusCode());
                self::assertTrue($data['result']['isError']);
                self::assertSame('The Agent profile is unavailable.', $data['result']['content'][0]['text']);
            } else {
                self::assertArrayHasKey('error', $data);
                self::assertSame($case === 'subclass' ? 500 : 400, $response->getStatusCode());
            }
        }
    }

    #[DataProviderExternal(PermissionMiddlewareTest::class, 'frameworks')]
    public function test_oauth_outcomes_are_not_jsend_or_permission_grants(string $framework): void
    {
        $responses = new OAuthResponseFactory(new ResponseFactory(), new StreamFactory(), 'example');
        $scopes = OAuthScopeSet::fromArray(['read']);
        $metadata = OAuthResourceMetadata::fromConfiguration(
            'https://example.test/mcp',
            ['https://issuer.test'],
            $scopes
        );
        foreach (OAuthFailure::cases() as $failure) {
            $expected = $responses->rejection($failure, $metadata, $scopes);
            $native = new NativePipeline(
                $framework,
                new PermissionMiddleware(
                    null,
                    static fn() => throw new RuntimeException('Ordinary provider must remain excluded.')
                ),
                protocol: static fn(): ResponseInterface => $responses->rejection($failure, $metadata, $scopes)
            );
            $response = $native->request(new ServerRequestFactory()->createServerRequest(
                'GET',
                'https://example.test/protocol'
            ));
            self::assertSame($expected->getStatusCode(), $response->getStatusCode());
            self::assertSame(
                $expected->getHeaderLine('WWW-Authenticate'),
                $response->getHeaderLine('WWW-Authenticate')
            );
            self::assertSame('', (string) $response->getBody());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame(0, $native->reports);
            self::assertSame(0, $native->validators);
        }
    }
}
