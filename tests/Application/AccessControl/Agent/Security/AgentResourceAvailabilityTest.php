<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentResourceAvailability;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpProtocolError;
use Fight\Common\Application\Mcp\McpProtocolException;
use Fight\Common\Application\Mcp\McpRequestMetadata;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\McpServerInfo;
use Fight\Common\Application\Mcp\Resource\McpResourceContent;
use Fight\Common\Application\Mcp\Resource\McpResourceDiscovery;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Application\Mcp\Resource\McpResourceLimits;
use Fight\Common\Application\Mcp\Resource\McpResourceReadLimits;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\ResourceProvider;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[CoversClass(AgentResourceAvailability::class)]
#[CoversClass(AgentResourcePermissionBinding::class)]
#[CoversClass(AgentResourcePermissionCatalog::class)]
final class AgentResourceAvailabilityTest extends TestCase
{
    private ?Agent $agent;

    private AgentRepository $agents;

    private InMemoryPermissionRepository $permissions;

    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryAgentRequestNonceConsumer $nonces;

    private int $requestNumber = 0;

    private int $closed = 0;

    private int $readBytes = 0;

    /** @var list<FixedHmacSignedAgentRequestVerifier> */
    private array $verifiers = [];

    /** @return iterable<string, array{string}> */
    public static function missingAuthority(): iterable
    {
        foreach (['unresolved', 'view', 'read', 'both', 'signature'] as $missing) {
            yield $missing => [$missing];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function authorityChanges(): iterable
    {
        foreach (['revocation', 'credential', 'permission'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_real_discovery_filters_before_pagination_and_direct_unlisted_reads_need_no_listing(): void
    {
        $provider = $this->resourceProvider([
            'docs:a-hidden' => 'private', 'docs:b' => 'B', 'docs:c' => 'C', 'docs:unlisted' => 'Saved link bytes'
        ], ['docs:unlisted']);
        $catalog = $this->catalog($provider, ['docs:b', 'docs:c', 'docs:unlisted']);
        self::assertSame(0, $provider->enumerations);
        self::assertSame([], $provider->opened);
        $availability = $this->availability($catalog, preResolve: true);
        $discovery = $this->discovery($catalog, $availability);
        $direct = $this->response($discovery, 'resources/read', ['uri' => 'docs:unlisted'])['result'];
        self::assertSame('Saved link bytes', $direct['contents'][0]['text']);
        self::assertSame(0, $provider->enumerations);
        $page = $this->response($discovery, 'resources/list')['result'];
        self::assertSame(['docs:b'], array_column($page['resources'], 'uri'));
        self::assertSame(0, $page['ttlMs']);
        self::assertSame('private', $page['cacheScope']);
        self::assertSame(48, strlen($page['nextCursor']));
        $provider->metadata['docs:a-hidden']['title'] = 'Hidden change cannot affect a visible cursor';
        self::assertSame(
            json_encode($page, JSON_THROW_ON_ERROR),
            json_encode($this->response($discovery, 'resources/list')['result'], JSON_THROW_ON_ERROR)
        );
        $next = $this->response($discovery, 'resources/list', ['cursor' => $page['nextCursor']])['result'];
        self::assertSame(['docs:c'], array_column($next['resources'], 'uri'));
        self::assertArrayNotHasKey('nextCursor', $next);
        self::assertSame(['docs:unlisted'], $provider->opened);
        self::assertSame(1, $this->closed);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(1, $this->unitOfWork->transactions);
        self::assertSame(1, $this->verifiers[0]->calls());
        self::assertStringNotContainsString('a-hidden', json_encode($page, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('missingAuthority')]
    public function test_missing_requirements_or_unresolved_authority_conceal_metadata_and_never_open(
        string $missing
    ): void {
        $provider = $this->resourceProvider(['docs:one' => 'Protected']);
        $catalog = $this->catalog($provider, ['docs:one']);
        if ($missing === 'unresolved') {
            $this->agent = null;
        } elseif (in_array($missing, ['view', 'read', 'both'], true)) {
            self::assertNotNull($this->agent);
            foreach (['view', 'read'] as $index => $permission) {
                if ($missing === $permission || $missing === 'both') {
                    $this->agent = $this->agent->revokePermission($this->permissionId($index), $this->now());
                }
            }
        }

        $availability = $this->availability($catalog, invalidSignature: $missing === 'signature');
        $discovery = $this->discovery($catalog, $availability);
        $page = $this->response($discovery, 'resources/list')['result'];
        self::assertSame([], $page['resources']);
        self::assertArrayNotHasKey('nextCursor', $page);
        $unknown = $this->response($discovery, 'resources/read', ['uri' => 'docs:unknown']);
        self::assertSame($unknown, $this->response($discovery, 'resources/read', ['uri' => 'docs:one']));
        self::assertSame(-32602, $unknown['error']['code']);
        self::assertSame([], $provider->opened);
        self::assertSame(0, $this->closed);
        self::assertLessThanOrEqual(1, $this->nonces->consumptionCalls());
        self::assertLessThanOrEqual(1, $this->verifiers[0]->calls());
    }

    #[DataProvider('authorityChanges')]
    public function test_saved_links_pages_and_reads_recheck_fresh_authority_but_short_requests_keep_snapshot(
        string $change
    ): void {
        $provider = $this->resourceProvider(['docs:one' => 'One', 'docs:two' => 'Two']);
        $catalog = $this->catalog($provider, ['docs:one', 'docs:two']);
        $same = $this->discovery($catalog, $this->availability($catalog));
        $first = $this->response($same, 'resources/list')['result'];
        self::assertArrayHasKey('nextCursor', $first);
        self::assertNotNull($this->agent);
        $this->agent = match ($change) {
            'revocation' => $this->agent->revoke($this->now()),
            'credential' => $this->agent->rotateRecoverableCredential(
                $this->credentialId(),
                $this->agent->getCredentialRevision(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000099'),
                'encrypted:rotated-secret',
                $this->now()
            ),
            'permission' => $this->agent->revokePermission($this->permissionId(1), $this->now()),
            default => throw new RuntimeException('Unknown authority change.')
        };
        $admitted = $this->response($same, 'resources/read', ['uri' => 'docs:one'])['result'];
        self::assertSame('One', $admitted['contents'][0]['text']);
        $later = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame([], $this->response($later, 'resources/list')['result']['resources']);
        self::assertSame(-32602, $this->response($later, 'resources/read', ['uri' => 'docs:one'])['error']['code']);
        $stale = $this->response($later, 'resources/list', ['cursor' => $first['nextCursor']]);
        self::assertSame(-32602, $stale['error']['code']);
        self::assertSame(['docs:one'], $provider->opened);
        if ($change === 'permission') {
            $this->agent = $this->agent->grantPermission($this->permissionId(1), $this->now());
            self::assertSame([], $this->response($later, 'resources/list')['result']['resources']);
            $fresh = $this->discovery($catalog, $this->availability($catalog));
            $regranted = $this->response($fresh, 'resources/read', ['uri' => 'docs:one'])['result'];
            self::assertSame('One', $regranted['contents'][0]['text']);
        } elseif ($change === 'credential') {
            $fresh = $this->discovery($catalog, $this->availability(
                $catalog,
                credential: $this->agent->getCredentialId(),
                secret: 'rotated-secret'
            ));
            $rotated = $this->response($fresh, 'resources/read', ['uri' => 'docs:two'])['result'];
            self::assertSame('Two', $rotated['contents'][0]['text']);
        }
    }

    public function test_callers_and_failed_authentication_are_isolated_and_rejected_resolution_is_not_retried(): void
    {
        $provider = $this->resourceProvider(['docs:one' => 'One']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $rejected = $this->discovery($catalog, $this->availability($catalog, invalidSignature: true));
        $permitted = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame([], $this->response($rejected, 'resources/list')['result']['resources']);
        $admitted = $this->response($permitted, 'resources/read', ['uri' => 'docs:one'])['result'];
        self::assertSame('One', $admitted['contents'][0]['text']);
        self::assertSame(-32602, $this->response($rejected, 'resources/read', ['uri' => 'docs:one'])['error']['code']);
        self::assertSame(1, $this->verifiers[0]->calls());
        self::assertSame(1, $this->verifiers[1]->calls());
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    /** @return iterable<string, array{string}> */
    public static function authorityFaults(): iterable
    {
        foreach (['storage', 'invalid snapshot', 'unexpected'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('authorityFaults')]
    public function test_authority_operational_faults_are_safe_internal_failures_not_empty_or_partial_success(
        string $fault
    ): void {
        $reads = 0;
        $lookup = static function () use (&$reads, $fault): array {
            ++$reads;
            if ($fault === 'storage') {
                throw new RuntimeException('Private database credential and content');
            }

            return [];
        };
        $this->permissions = new InMemoryPermissionRepository(getByIdsResult: $lookup);
        if ($fault === 'unexpected') {
            $this->agent = null;
        }

        $provider = $this->resourceProvider(['docs:a-unmapped' => 'Hidden', 'docs:one' => 'One']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $availability = $this->availability($catalog, correlationId: $fault === 'unexpected' ? '' : 'fault');
        $discovery = $this->discovery($catalog, $availability);
        foreach (['resources/list', 'resources/read', 'resources/list'] as $method) {
            $parameters = $method === 'resources/read' ? ['uri' => 'docs:one'] : [];
            $response = $this->response($discovery, $method, $parameters, true);
            self::assertSame(['code' => -32603, 'message' => 'Internal error.'], $response['error']);
            self::assertArrayNotHasKey('result', $response);
            self::assertStringNotContainsString('Private', json_encode($response, JSON_THROW_ON_ERROR));
        }

        self::assertSame($fault === 'unexpected' ? 0 : 1, $reads);
        self::assertSame([], $provider->opened);
    }

    public function test_authority_change_during_nonce_consumption_is_a_deliberate_denial(): void
    {
        $change = function (): void {
            self::assertNotNull($this->agent);
            $this->agent = $this->agent->revoke($this->now());
        };
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agents, $this->unitOfWork, afterConsume: $change);
        $provider = $this->resourceProvider(['docs:one' => 'One']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame([], $this->response($discovery, 'resources/list')['result']['resources']);
        self::assertSame(-32602, $this->response($discovery, 'resources/read', ['uri' => 'docs:one'])['error']['code']);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame([], $provider->opened);
    }

    /** @return iterable<string, array{string}> */
    public static function aliases(): iterable
    {
        $uris = [
            'docs:%6fne', 'docs:folder/../one', 'DOCS:one', 'docs:one#fragment',
            'file:///etc/passwd', 'https://example.org/one'
        ];
        foreach ($uris as $uri) {
            yield $uri => [$uri];
        }
    }

    #[DataProvider('aliases')]
    public function test_aliases_and_external_locations_cannot_resolve_or_borrow_a_registered_identity(
        string $uri
    ): void {
        $provider = $this->resourceProvider(['docs:one' => 'One']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame(-32602, $this->response($discovery, 'resources/read', ['uri' => $uri])['error']['code']);
        self::assertSame([], $provider->opened);
        self::assertSame(0, $this->nonces->consumptionCalls());
    }

    public function test_normalizing_provider_and_reassigned_owner_cannot_borrow_requirements(): void
    {
        $provider = $this->resourceProvider(['docs:one' => 'One']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $descriptor = $provider->find('docs:one');
        $provider->lookup = static fn(): ?McpResourceInfo => $descriptor;
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $normalized = $this->response($discovery, 'resources/read', ['uri' => 'docs:%6fne'], true);
        self::assertSame(-32603, $normalized['error']['code']);
        $provider->lookup = static fn(): ?McpResourceInfo => null;
        $foreign = $this->resourceProvider(['docs:one' => 'Forged new owner']);
        $swapped = new McpResourceDiscovery(
            [$foreign],
            $this->availability($catalog),
            str_repeat('k', 32),
            'resource-tests',
            readLimits: new McpResourceReadLimits()
        );
        self::assertSame(-32602, $this->response($swapped, 'resources/read', ['uri' => 'docs:one'])['error']['code']);
        self::assertSame([], $provider->opened);
        self::assertSame([], $foreign->opened);
    }

    public function test_concealed_duplicate_ownership_remains_an_internal_failure_before_any_content(): void
    {
        $one = $this->resourceProvider(['docs:a' => 'A', 'docs:collision' => 'Private']);
        $two = $this->resourceProvider(['docs:b' => 'B']);
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($one, 'docs:a', ['VIEW_DOCUMENTS']),
            new AgentResourcePermissionBinding($two, 'docs:b', ['VIEW_DOCUMENTS'])
        ]);
        $two->metadata['docs:collision'] = $one->metadata['docs:collision'];
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame(-32603, $this->response($discovery, 'resources/list', [], true)['error']['code']);
        $collision = $this->response($discovery, 'resources/read', ['uri' => 'docs:collision'], true);
        self::assertSame(-32603, $collision['error']['code']);
        self::assertSame([], $one->opened);
        self::assertSame([], $two->opened);
    }

    /** @return iterable<string, array{string}> */
    public static function providerFaults(): iterable
    {
        foreach (['lookup', 'protocol lookup', 'policy', 'protocol policy', 'enumeration', 'open'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('providerFaults')]
    public function test_provider_and_policy_faults_retain_common_safe_failure_boundary(string $fault): void
    {
        $content = null;
        if ($fault === 'open') {
            $content = static function (): never {
                throw new RuntimeException('Private provider path');
            };
        }

        $provider = $this->resourceProvider(['docs:one' => 'One'], content: $content);
        $catalog = $this->catalog($provider, ['docs:one']);
        if ($fault === 'lookup' || $fault === 'protocol lookup') {
            $provider->lookup = static function () use ($fault): never {
                if ($fault === 'protocol lookup') {
                    throw new McpProtocolException(McpProtocolError::invalidParams(), 1);
                }

                throw new RuntimeException('Private lookup path');
            };
        } elseif ($fault === 'policy' || $fault === 'protocol policy') {
            $provider->policy = static function () use ($fault): never {
                if ($fault === 'protocol policy') {
                    throw new McpProtocolException(McpProtocolError::invalidParams(), 1);
                }

                throw new RuntimeException('Private policy path');
            };
        } elseif ($fault === 'enumeration') {
            $provider->metadata['docs:one']['name'] = 42;
        }

        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $method = $fault === 'enumeration' ? 'resources/list' : 'resources/read';
        $parameters = $method === 'resources/read' ? ['uri' => 'docs:one'] : [];
        $response = $this->response($discovery, $method, $parameters, true);
        self::assertSame(-32603, $response['error']['code']);
        self::assertStringNotContainsString('Private', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertSame($fault === 'open' ? ['docs:one'] : [], $provider->opened);
    }

    public function test_provider_owned_denial_supplements_general_permission_checks(): void
    {
        $provider = $this->resourceProvider(['docs:one' => 'One']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $provider->allowed = false;
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame([], $this->response($discovery, 'resources/list')['result']['resources']);
        self::assertSame(-32602, $this->response($discovery, 'resources/read', ['uri' => 'docs:one'])['error']['code']);
        self::assertSame([], $provider->opened);
    }

    /** @return iterable<string, array{bool}> */
    public static function byteKinds(): iterable
    {
        yield 'UTF-8 text and inert script' => [false];
        yield 'binary' => [true];
    }

    #[DataProvider('byteKinds')]
    public function test_permitted_content_preserves_exact_bytes_and_metadata_and_always_closes(bool $binary): void
    {
        $bytes = $binary ? "\x00\xff\x01\x80" : "é\r\n<script>not executed</script>\n";
        $provider = $this->resourceProvider(['docs:one' => $bytes], binary: $binary);
        $catalog = $this->catalog($provider, ['docs:one']);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $result = $this->response($discovery, 'resources/read', ['uri' => 'docs:one'])['result'];
        $item = $result['contents'][0];
        self::assertSame('docs:one', $item['uri']);
        self::assertSame($binary ? 'application/octet-stream' : 'text/plain', $item['mimeType']);
        self::assertSame($bytes, $binary ? base64_decode($item['blob'], true) : $item['text']);
        self::assertSame(0, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertSame(strlen($bytes), $this->readBytes);
        self::assertSame(1, $this->closed);
        $this->response($discovery, 'resources/read', ['uri' => 'docs:one']);
        self::assertSame(['docs:one', 'docs:one'], $provider->opened);
        self::assertSame(2, $this->closed);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    /** @return iterable<string, array{string}> */
    public static function contentFaults(): iterable
    {
        foreach (['overflow', 'descriptor', 'digest', 'invalid text'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('contentFaults')]
    public function test_stream_faults_fail_without_partial_bytes_and_close_once(string $fault): void
    {
        $content = function (McpResourceInfo $info) use ($fault): McpResourceContent {
            $bytes = match ($fault) {
                'overflow' => str_repeat('x', 100),
                'invalid text' => "\xff",
                default => 'One'
            };
            if ($fault === 'descriptor') {
                $info = McpResourceInfo::fromArray(['uri' => 'docs:foreign', 'name' => 'Foreign']);
            }

            $digest = $fault === 'digest' ? 'sha256:'.str_repeat('0', 64) : null;

            return McpResourceContent::text($info, $this->stream($bytes), $digest);
        };
        $provider = $this->resourceProvider(['docs:one' => 'One'], content: $content);
        unset($provider->metadata['docs:one']['size']);
        $catalog = $this->catalog($provider, ['docs:one']);
        $discovery = $this->discovery($catalog, $this->availability($catalog), new McpResourceReadLimits(8, 2048));
        $response = $this->response($discovery, 'resources/read', ['uri' => 'docs:one'], true);
        self::assertSame(-32603, $response['error']['code']);
        self::assertArrayNotHasKey('result', $response);
        self::assertSame(1, $this->closed);
        self::assertLessThanOrEqual(9, $this->readBytes);
    }

    public function test_finite_descriptor_scan_and_encoded_budgets_are_not_bypassed_even_for_hidden_entries(): void
    {
        $provider = $this->resourceProvider(['docs:one' => str_repeat('x', 20)]);
        $catalog = $this->catalog($provider, ['docs:one']);
        $discovery = $this->discovery($catalog, $this->availability($catalog), new McpResourceReadLimits(8, 2048));
        self::assertSame(-32603, $this->response($discovery, 'resources/list', [], true)['error']['code']);
        $oversized = $this->response($discovery, 'resources/read', ['uri' => 'docs:one'], true);
        self::assertSame(-32603, $oversized['error']['code']);
        $provider->metadata['docs:one']['size'] = 1;
        $provider->metadata['docs:one']['description'] = str_repeat('x', 17000);
        $normal = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame(-32603, $this->response($normal, 'resources/list', [], true)['error']['code']);
        $oversized = $this->response($normal, 'resources/read', ['uri' => 'docs:one'], true);
        self::assertSame(-32603, $oversized['error']['code']);
        unset($provider->metadata['docs:one']['description']);
        $encoded = $this->discovery($catalog, $this->availability($catalog), new McpResourceReadLimits(8, 304));
        self::assertSame(-32603, $this->response($encoded, 'resources/list', [], true)['error']['code']);
        $provider->metadata['docs:two'] = ['uri' => 'docs:two', 'name' => 'Concealed, but still counted'];
        $bounded = new McpResourceDiscovery(
            $catalog->providers(),
            $this->availability($catalog),
            str_repeat('k', 32),
            'resource-tests',
            new McpResourceLimits(pageSize: 1, maxDescriptors: 1),
            readLimits: new McpResourceReadLimits()
        );
        self::assertSame(-32603, $this->response($bounded, 'resources/list', [], true)['error']['code']);
        self::assertSame([], $provider->opened);
    }

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->permissions = new InMemoryPermissionRepository();
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('Resource conformance Agent'),
            $this->credentialId(),
            'encrypted:fixture-secret',
            $this->now()
        );
        foreach (['VIEW_DOCUMENTS', 'READ_DOCUMENTS'] as $index => $name) {
            $id = $this->permissionId($index);
            $this->permissions->add(Permission::define($id, PermissionName::fromString($name), $this->now()));
            $this->agent = $this->agent->grantPermission($id, $this->now());
        }

        $this->agents = $this->createStub(AgentRepository::class);
        $this->agents->method('getByCredentialId')->willReturnCallback(
            $this->agentByCredential(...)
        );
        $this->agents->method('getById')->willReturnCallback($this->currentAgent(...));
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agents, $this->unitOfWork);
    }

    private function availability(
        AgentResourcePermissionCatalog $catalog,
        bool $invalidSignature = false,
        bool $preResolve = false,
        ?AgentCredentialId $credential = null,
        string $secret = 'fixture-secret',
        string $correlationId = 'resource-conformance'
    ): AgentResourceAvailability {
        ++$this->requestNumber;
        $request = $this->signedRequest('valid', $credential);
        $verifier = new FixedHmacSignedAgentRequestVerifier($request, $secret);
        $this->verifiers[] = $verifier;
        $provider = new CurrentAgentPrincipalProvider(
            $this->agents,
            $this->permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock($this->now()),
            $this->nonces,
            $this->unitOfWork
        );
        if ($preResolve) {
            $provider->resolve($request, $correlationId);
        }

        return new AgentResourceAvailability(
            $catalog,
            $provider,
            $invalidSignature ? $this->signedRequest('invalid', $credential) : $request,
            $correlationId
        );
    }

    private function signedRequest(string $signature, ?AgentCredentialId $credential): SignedAgentRequest
    {
        return new SignedAgentRequest(
            'POST',
            'mcp.example',
            '/mcp',
            '',
            $this->now(),
            'resource-nonce-'.$this->requestNumber,
            $credential ?? $this->credentialId(),
            'HMAC-SHA256',
            $signature,
            null,
            ''
        );
    }

    /** @param list<string> $uris */
    private function catalog(ResourceProvider $provider, array $uris): AgentResourcePermissionCatalog
    {
        return new AgentResourcePermissionCatalog(array_map(
            static fn(string $uri): AgentResourcePermissionBinding => new AgentResourcePermissionBinding(
                $provider,
                $uri,
                ['VIEW_DOCUMENTS', 'READ_DOCUMENTS']
            ),
            $uris
        ));
    }

    private function discovery(
        AgentResourcePermissionCatalog $catalog,
        AgentResourceAvailability $availability,
        ?McpResourceReadLimits $limits = null
    ): McpResourceDiscovery {
        return new McpResourceDiscovery(
            $catalog->providers(),
            $availability,
            str_repeat('k', 32),
            'resource-tests',
            new McpResourceLimits(pageSize: 1),
            ttlMs: 0,
            cacheScope: 'private',
            readLimits: $limits ?? new McpResourceReadLimits()
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function response(
        McpResourceDiscovery $discovery,
        string $method,
        array $parameters = [],
        bool $failure = false
    ): array {
        $diagnostics = $this->createMock(McpDiagnostics::class);
        $diagnostics->expects(self::exactly($failure ? 1 : 0))->method('record');
        $registry = new McpCapabilityRegistry(new McpServerInfo('resource-conformance', '1'), [$discovery]);
        $responder = new McpResponder($registry, diagnostics: $diagnostics);

        return $responder->respond(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => [...$parameters, '_meta' => [
                McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([])
            ]]
        ], JSON_THROW_ON_ERROR))->toArray();
    }

    /**
     * @param array<string, string> $entries
     * @param list<string>          $unlisted
     */
    private function resourceProvider(
        array $entries,
        array $unlisted = [],
        bool $binary = false,
        ?Closure $content = null
    ): ResourceProvider {
        $metadata = [];
        foreach ($entries as $uri => $bytes) {
            $metadata[$uri] = [
                'uri'      => $uri,
                'name'     => $uri,
                'mimeType' => $binary ? 'application/octet-stream' : 'text/plain',
                'size'     => strlen($bytes),
                '_meta'    => ['example.org/data' => ['label' => 'Fresh nested metadata']]
            ];
        }

        $open = function (McpResourceInfo $resource) use ($entries, $binary): McpResourceContent {
            $bytes = $entries[$resource->uri()];
            $stream = $this->stream($bytes);
            $digest = 'sha256:'.hash('sha256', $bytes);
            if ($binary) {
                return McpResourceContent::binary($resource, $stream, $digest);
            }

            return McpResourceContent::text($resource, $stream, $digest);
        };

        return new ResourceProvider($metadata, $content ?? $open, $unlisted);
    }

    private function stream(string $bytes): StreamInterface
    {
        $offset = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('tell')->willReturn(0);
        $stream->method('read')->willReturnCallback(function (int $length) use ($bytes, &$offset): string {
            $chunk = substr($bytes, $offset, $length);
            $offset += strlen($chunk);
            $this->readBytes += strlen($chunk);

            return $chunk;
        });
        $stream->method('eof')->willReturnCallback(static function () use (&$offset, $bytes): bool {
            return $offset >= strlen($bytes);
        });
        $stream->expects(self::once())->method('close')->willReturnCallback(function (): void {
            ++$this->closed;
        });
        $stream->expects(self::never())->method('getContents');
        $stream->expects(self::never())->method('__toString');

        return $stream;
    }

    private function agentByCredential(AgentCredentialId $id): ?Agent
    {
        return $this->agent?->getCredentialId()->equals($id) ? $this->agent : null;
    }

    private function currentAgent(): ?Agent
    {
        return $this->agent;
    }

    private function permissionId(int $index): PermissionId
    {
        return PermissionId::fromString(sprintf('018f0000-0000-7000-8000-%012d', $index + 10));
    }

    private function credentialId(): AgentCredentialId
    {
        return AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-26T12:00:00+00:00');
    }
}
