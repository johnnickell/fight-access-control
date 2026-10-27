<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentResourceAvailability;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentSkillAvailability;
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
use Fight\Common\Application\Mcp\Resource\McpResourceAvailability;
use Fight\Common\Application\Mcp\Resource\McpResourceDiscovery;
use Fight\Common\Application\Mcp\Resource\McpResourceReadLimits;
use Fight\Common\Application\Mcp\Skill\McpSkillDiscovery;
use Fight\Common\Application\Mcp\Skill\McpSkillDiscoveryLimits;
use Fight\Common\Application\Mcp\Skill\McpSkillResources;
use Fight\Common\Application\Mcp\Skill\McpSkillRevision;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\ResourceProvider;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\SkillRevision;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[CoversClass(AgentSkillAvailability::class)]
#[CoversClass(AgentSkillPermissionBinding::class)]
#[CoversClass(AgentSkillPermissionCatalog::class)]
final class AgentSkillAvailabilityTest extends TestCase
{
    private ?Agent $agent;

    private AgentRepository $agents;

    private InMemoryPermissionRepository $permissions;

    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryAgentRequestNonceConsumer $nonces;

    private int $requestNumber = 0;

    /** @var list<string> */
    private array $opened = [];

    private int $closed = 0;

    /** @var list<FixedHmacSignedAgentRequestVerifier> */
    private array $verifiers = [];

    /** @return iterable<string, array{int}> */
    public static function permissionCombinations(): iterable
    {
        for ($mask = 0; $mask < 32; ++$mask) {
            yield 'granted mask '.$mask => [$mask];
        }
    }

    #[DataProvider('permissionCombinations')]
    public function test_complete_entries_and_direct_members_apply_independent_permissions(int $mask): void
    {
        self::assertNotNull($this->agent);
        for ($index = 0; $index < 5; ++$index) {
            if (($mask & (1 << $index)) === 0) {
                $this->agent = $this->agent->revokePermission($this->permissionId($index), $this->now());
            }
        }

        $revision = SkillRevision::create();
        $composition = $this->composition([$revision]);
        $root = $revision->entry()->get('uri');
        $whole = ($mask & 3) === 3;
        $all = $mask === 31;
        $members = array_keys($revision->resources());
        $expectedOpened = [];
        foreach ($members as $index => $uri) {
            // Reading the selected file does not require either other file's Resource grant or prior discovery.
            $admitted = $whole && ($mask & (1 << ($index + 2))) !== 0;
            $read = $this->response($composition, 'resources/read', ['uri' => $uri]);
            if ($admitted) {
                self::assertArrayHasKey('result', $read);
                $expectedOpened[] = $revision->file($uri)->bytes;
            } else {
                self::assertSame($this->unknown($composition), $read);
            }
        }

        $list = $this->response($composition, 'skills/list')['result'];
        self::assertSame($all ? [$this->entry($revision)] : [], $list['skills']);
        self::assertArrayNotHasKey('nextCursor', $list);
        self::assertSame('complete', $list['resultType']);
        self::assertSame(0, $list['ttlMs']);
        self::assertSame('private', $list['cacheScope']);
        $get = $this->response($composition, 'skills/get', ['uri' => $root]);
        if ($all) {
            self::assertSame($this->entry($revision), $get['result']['skill']);
        } else {
            self::assertSame($this->unknown($composition, 'skills/get'), $get);
            self::assertStringNotContainsString('Protected work', json_encode($list, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('references', json_encode($get, JSON_THROW_ON_ERROR));
        }

        $resources = $this->response($composition, 'resources/list')['result']['resources'];
        self::assertCount(count($expectedOpened), $resources);
        self::assertSame($expectedOpened, $this->opened);
        self::assertSame(count($expectedOpened), $this->closed);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(1, $this->verifiers[0]->calls());
        self::assertSame(1, $this->unitOfWork->transactions);
    }

    public function test_private_pages_historical_gets_and_exact_text_binary_bytes_preserve_complete_integrity(): void
    {
        $revisions = [SkillRevision::create('v0'), SkillRevision::create('v1')];
        $composition = $this->composition($revisions, preResolve: true);
        $page = $this->response($composition, 'skills/list')['result'];
        self::assertSame([$this->entry($revisions[0])], $page['skills']);
        self::assertSame(48, strlen($page['nextCursor']));
        $next = $this->response($composition, 'skills/list', ['cursor' => $page['nextCursor']])['result'];
        self::assertSame([$this->entry($revisions[1])], $next['skills']);
        self::assertArrayNotHasKey('nextCursor', $next);
        self::assertSame([], $this->opened);
        foreach ($revisions as $revision) {
            $get = $this->response($composition, 'skills/get', ['uri' => $revision->entry()->get('uri')])['result'];
            self::assertSame($this->entry($revision), $get['skill']);
            foreach ($get['skill']['resources'] as $member) {
                $file = $revision->file($member['uri']);
                $read = $this->response($composition, 'resources/read', ['uri' => $member['uri']])['result'];
                $content = $read['contents'][0];
                self::assertSame($file->bytes, $file->text ? $content['text'] : base64_decode($content['blob'], true));
                self::assertSame($member['uri'], $content['uri']);
                self::assertSame($file->mimeType, $content['mimeType']);
                self::assertSame('sha256:'.hash('sha256', $file->bytes), $member['digest']);
                self::assertSame(strlen($file->bytes), $member['size']);
                self::assertSame('private', $read['cacheScope']);
                self::assertSame(0, $read['ttlMs']);
            }
        }

        self::assertCount(6, $this->opened);
        self::assertSame(6, $this->closed);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(1, $this->verifiers[0]->calls());
    }

    /** @return iterable<string, array{string}> */
    public static function changes(): iterable
    {
        foreach (['revocation', 'rotation', 'whole permission', 'member permission'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('changes')]
    public function test_fresh_historical_reads_observe_changes_but_short_requests_keep_snapshot(string $change): void
    {
        $old = SkillRevision::create('v0');
        $revisions = [$old, SkillRevision::create()];
        $same = $this->composition($revisions);
        $page = $this->response($same, 'skills/list')['result'];
        self::assertNotNull($this->agent);
        $this->agent = match ($change) {
            'revocation' => $this->agent->revoke($this->now()),
            'rotation' => $this->agent->rotateRecoverableCredential(
                $this->credentialId(),
                $this->agent->getCredentialRevision(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000099'),
                'encrypted:rotated-secret',
                $this->now()
            ),
            'whole permission' => $this->agent->revokePermission($this->permissionId(1), $this->now()),
            'member permission' => $this->agent->revokePermission($this->permissionId(3), $this->now()),
            default => throw new RuntimeException('Unknown change.')
        };
        $uri = array_keys($old->resources())[1];
        self::assertArrayHasKey('result', $this->response($same, 'skills/get', [
            'uri' => $old->entry()->get('uri')
        ]));
        self::assertArrayHasKey('result', $this->response($same, 'resources/read', ['uri' => $uri]));
        $later = $this->composition($revisions);
        self::assertSame([], $this->response($later, 'skills/list')['result']['skills']);
        $continuation = $this->response($later, 'skills/list', ['cursor' => $page['nextCursor']]);
        self::assertSame(-32602, $continuation['error']['code']);
        self::assertSame(
            $this->unknown($later, 'skills/get'),
            $this->response($later, 'skills/get', ['uri' => $old->entry()->get('uri')])
        );
        self::assertSame($this->unknown($later), $this->response($later, 'resources/read', ['uri' => $uri]));
        self::assertCount(1, $this->opened);
        if ($change === 'rotation') {
            $fresh = $this->composition(
                $revisions,
                credential: $this->agent->getCredentialId(),
                secret: 'rotated-secret'
            );
            self::assertArrayHasKey('result', $this->response($fresh, 'resources/read', ['uri' => $uri]));
        } elseif ($change !== 'revocation') {
            $this->agent = $this->agent->grantPermission(
                $this->permissionId($change === 'whole permission' ? 1 : 3),
                $this->now()
            );
            self::assertSame([], $this->response($later, 'skills/list')['result']['skills']);
            self::assertCount(1, $this->response($this->composition($revisions), 'skills/list')['result']['skills']);
        }
    }

    public function test_unknown_and_inexact_entries_deny_without_authentication_or_stream_allocation(): void
    {
        $revision = SkillRevision::create();
        $catalog = new AgentSkillPermissionCatalog([new AgentSkillPermissionBinding($revision, ['READ_SKILLS'])]);
        [$provider, $request] = $this->authority();
        $availability = new AgentSkillAvailability($catalog, $provider, $request, 'unknown');
        self::assertFalse($availability->isAvailable(SkillRevision::create('foreign')->entry()));
        self::assertFalse($availability->isAvailable($revision->entry()->with('resources', [])));
        self::assertSame(0, $this->nonces->consumptionCalls());
        self::assertSame(0, $this->verifiers[0]->calls());
        $composition = $this->composition([$revision]);
        foreach (['%53KILL.md', '../work/SKILL.md', 'SKILL.md#fragment', 'references/%67uide.md', 'unknown'] as $path) {
            $uri = 'skill://catalog/v1/work/'.$path;
            self::assertSame(
                $this->unknown($composition),
                $this->response($composition, 'resources/read', ['uri' => $uri])
            );
            self::assertSame(
                $this->unknown($composition, 'skills/get'),
                $this->response($composition, 'skills/get', ['uri' => $uri])
            );
        }

        self::assertSame([], $this->opened);
        self::assertSame(0, $this->nonces->consumptionCalls());
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedAuthority(): iterable
    {
        foreach (['missing Agent', 'signature', 'changed during nonce'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('rejectedAuthority')]
    public function test_rejected_requests_stay_denied_and_isolated_without_per_member_nonce_replays(string $case): void
    {
        if ($case === 'missing Agent') {
            $this->agent = null;
        } elseif ($case === 'changed during nonce') {
            $this->nonces = new InMemoryAgentRequestNonceConsumer(
                $this->agents,
                $this->unitOfWork,
                afterConsume: function (): void {
                    $this->agent = $this->agent?->revoke($this->now());
                }
            );
        }

        $revision = SkillRevision::create();
        $rejected = $this->composition([$revision], invalidSignature: $case === 'signature');
        self::assertSame([], $this->response($rejected, 'skills/list')['result']['skills']);
        self::assertSame(
            $this->unknown($rejected),
            $this->response($rejected, 'resources/read', ['uri' => array_keys($revision->resources())[0]])
        );
        self::assertSame([], $this->opened);
        self::assertLessThanOrEqual(1, $this->verifiers[0]->calls());
        self::assertLessThanOrEqual(1, $this->nonces->consumptionCalls());
        if ($case === 'signature') {
            $allowed = $this->composition([$revision]);
            self::assertCount(1, $this->response($allowed, 'skills/list')['result']['skills']);
            self::assertSame([], $this->response($rejected, 'skills/list')['result']['skills']);
            self::assertSame(1, $this->verifiers[0]->calls());
            self::assertSame(1, $this->verifiers[1]->calls());
            self::assertSame(1, $this->nonces->consumptionCalls());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        foreach (['storage', 'snapshot', 'unexpected'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('faults')]
    public function test_whole_skill_authority_faults_are_safe_failures_not_empty_or_partial_success(string $case): void
    {
        $reads = 0;
        $this->permissions = new InMemoryPermissionRepository(
            getByIdsResult: static function () use ($case, &$reads): array {
                ++$reads;
                if ($case === 'storage') {
                    throw new RuntimeException('Private credential and file path');
                }

                return [];
            }
        );
        $revision = SkillRevision::create();
        $composition = $this->composition([$revision], correlationId: $case === 'unexpected' ? '' : 'fault');
        foreach (['skills/list', 'skills/get', 'resources/read', 'skills/list'] as $method) {
            $response = $this->response(
                $composition,
                $method,
                $method === 'skills/list' ? [] : ['uri' => $revision->entry()->get('uri')],
                true
            );
            self::assertSame(['code' => -32603, 'message' => 'Internal error.'], $response['error']);
            self::assertArrayNotHasKey('result', $response);
            self::assertStringNotContainsString('Private', json_encode($response, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('work', json_encode($response, JSON_THROW_ON_ERROR));
        }

        self::assertSame(1, $reads);
        self::assertSame([], $this->opened);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_resource_first_and_whole_skill_decisions_share_previously_resolved_trusted_authority(): void
    {
        $revision = SkillRevision::create();
        $composition = $this->composition([$revision]);
        self::assertTrue($composition[0]->permits($composition[2]->find($revision->entry()->get('uri'))));
        self::assertCount(1, $this->response($composition, 'skills/list')['result']['skills']);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(1, $this->verifiers[0]->calls());
    }

    public function test_unmapped_whole_skill_denies_even_with_general_resource_permission(): void
    {
        $revision = SkillRevision::create();
        $composition = $this->composition([$revision], unmapped: true);
        self::assertSame([], $this->response($composition, 'skills/list')['result']['skills']);
        self::assertSame(
            $this->unknown($composition),
            $this->response($composition, 'resources/read', ['uri' => $revision->entry()->get('uri')])
        );
        self::assertSame([], $this->opened);
        self::assertSame(0, $this->nonces->consumptionCalls());
    }

    public function test_unmapped_member_conceals_complete_entry_but_other_selected_members_remain_independent(): void
    {
        $revision = SkillRevision::create();
        $composition = $this->composition([$revision], unmappedMember: true);
        self::assertSame([], $this->response($composition, 'skills/list')['result']['skills']);
        self::assertSame($this->unknown($composition, 'skills/get'), $this->response(
            $composition,
            'skills/get',
            ['uri' => $revision->entry()->get('uri')]
        ));
        $guide = array_keys($revision->resources())[1];
        self::assertSame($this->unknown($composition), $this->response(
            $composition,
            'resources/read',
            ['uri' => $guide]
        ));
        self::assertSame([], $this->opened);
        self::assertArrayHasKey('result', $this->response(
            $composition,
            'resources/read',
            ['uri' => $revision->entry()->get('uri')]
        ));
        self::assertCount(1, $this->opened);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_direct_common_provider_open_cannot_skip_whole_skill_admission(): void
    {
        self::assertNotNull($this->agent);
        $this->agent = $this->agent->revokePermission($this->permissionId(1), $this->now());
        $revision = SkillRevision::create();
        $composition = $this->composition([$revision]);
        try {
            $composition[2]->open($composition[2]->find($revision->entry()->get('uri')));
            self::fail('Direct open must not bypass whole-Skill permission.');
        } catch (DomainException) {
            self::assertSame([], $this->opened);
            self::assertSame(0, $this->closed);
        }
    }

    public function test_encoded_serving_bounds_and_hidden_ownership_conflicts_cannot_be_bypassed(): void
    {
        $revision = SkillRevision::create();
        $composition = $this->composition([$revision], readLimits: new McpResourceReadLimits(8, 2048));
        self::assertSame(-32603, $this->response($composition, 'skills/list', [], true)['error']['code']);
        self::assertSame([], $this->opened);
        $composition = $this->composition([$revision]);
        $duplicate = new ResourceProvider([
            $revision->entry()->get('uri') => $revision->resources()[$revision->entry()->get('uri')]->toArray()
        ], static fn(): never => throw new RuntimeException('Must not open'));
        $resources = new McpResourceDiscovery(
            [$composition[2], $duplicate],
            $this->createStub(McpResourceAvailability::class),
            str_repeat('k', 32),
            'skills',
            readLimits: new McpResourceReadLimits()
        );
        $conflicted = [
            $resources,
            new McpSkillDiscovery($composition[2], $resources, str_repeat('k', 32), 'skills'),
            $composition[2]
        ];
        self::assertSame(-32603, $this->response($conflicted, 'skills/list', [], true)['error']['code']);
        self::assertSame([], $duplicate->opened);
        self::assertSame([], $this->opened);
    }

    public function test_tight_encoded_pages_preserve_complete_entries_and_changed_catalogs_reject_saved_cursors(): void
    {
        $revisions = [SkillRevision::create('v0'), SkillRevision::create('v1'), SkillRevision::create('v2')];
        $size = strlen($revisions[0]->entry()->toString());
        $limits = new McpSkillDiscoveryLimits(pageSize: 100, maxEntryBytes: $size, maxResultBytes: $size + 512);
        $composition = $this->composition($revisions, skillLimits: $limits);
        $parameters = [];
        $firstCursor = '';
        foreach ($revisions as $index => $revision) {
            $page = $this->response($composition, 'skills/list', $parameters)['result'];
            self::assertSame([$this->entry($revision)], $page['skills']);
            self::assertLessThanOrEqual($limits->maxResultBytes, strlen(json_encode($page, JSON_THROW_ON_ERROR)));
            if ($index < 2) {
                $parameters = ['cursor' => $page['nextCursor']];
                if ($index === 0) {
                    $firstCursor = $page['nextCursor'];
                }
            } else {
                self::assertArrayNotHasKey('nextCursor', $page);
            }
        }

        $changed = $this->composition([
            $revisions[0], $revisions[1], SkillRevision::create('v3')
        ], skillLimits: $limits);
        self::assertSame(-32602, $this->response($changed, 'skills/list', ['cursor' => $firstCursor])['error']['code']);
        self::assertSame([], $this->opened);
    }

    public function test_concealed_entry_changes_do_not_enter_visible_pages_or_cursor_context(): void
    {
        $visible = [SkillRevision::create('v1'), SkillRevision::create('v2')];
        $hidden = SkillRevision::create('v0', 'Concealed private description');
        $composition = $this->composition([$hidden, ...$visible], concealed: [$hidden]);
        $page = $this->response($composition, 'skills/list')['result'];
        self::assertSame([$this->entry($visible[0])], $page['skills']);
        self::assertSame($this->unknown($composition, 'skills/get'), $this->response(
            $composition,
            'skills/get',
            ['uri' => $hidden->entry()->get('uri')]
        ));
        $changedHidden = SkillRevision::create('hidden', 'Changed concealed frontmatter');
        $changed = $this->composition([$changedHidden, ...$visible], concealed: [$changedHidden]);
        self::assertSame($page, $this->response($changed, 'skills/list')['result']);
        $next = $this->response($changed, 'skills/list', ['cursor' => $page['nextCursor']])['result'];
        self::assertSame([$this->entry($visible[1])], $next['skills']);
        self::assertArrayNotHasKey('nextCursor', $next);
        self::assertStringNotContainsString('Concealed', json_encode($page, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->opened);
    }

    public function test_common_rejects_wrong_provider_or_resource_capability_identity_statically(): void
    {
        $composition = $this->composition([SkillRevision::create()]);
        $other = $this->composition([SkillRevision::create()]);
        $this->expectException(DomainException::class);
        new McpSkillDiscovery($composition[2], $other[0], str_repeat('k', 32), 'skills');
    }

    /** @return iterable<string, array{bool}> */
    public static function policyFaults(): iterable
    {
        yield 'operational' => [false];
        yield 'protocol injection' => [true];
    }

    #[DataProvider('policyFaults')]
    public function test_member_policy_faults_do_not_fabricate_partial_manifest_success(bool $protocol): void
    {
        $composition = $this->composition([SkillRevision::create()]);
        // This supplemental general-policy fault occurs after actual whole-Skill Permission admission.
        $policy = $this->createStub(McpResourceAvailability::class);
        $policy->method('isAvailable')->willReturnCallback(static function () use ($protocol): never {
            if ($protocol) {
                throw new McpProtocolException(McpProtocolError::invalidParams(), 1);
            }

            throw new RuntimeException('Private path');
        });
        $resources = new McpResourceDiscovery(
            [$composition[2]],
            $policy,
            str_repeat('k', 32),
            'skills',
            readLimits: new McpResourceReadLimits()
        );
        $faulted = [
            $resources,
            new McpSkillDiscovery($composition[2], $resources, str_repeat('k', 32), 'skills'),
            $composition[2]
        ];
        foreach (['skills/list', 'skills/get', 'resources/read'] as $method) {
            $response = $this->response(
                $faulted,
                $method,
                $method === 'skills/list' ? [] : ['uri' => 'skill://catalog/v1/work/SKILL.md'],
                true
            );
            self::assertSame(['code' => -32603, 'message' => 'Internal error.'], $response['error']);
            self::assertArrayNotHasKey('result', $response);
        }

        self::assertSame([], $this->opened);
    }

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->permissions = new InMemoryPermissionRepository();
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('Skill conformance Agent'),
            $this->credentialId(),
            'encrypted:fixture-secret',
            $this->now()
        );
        foreach (['VIEW_SKILLS', 'READ_SKILLS', 'READ_ASSETS', 'READ_GUIDES', 'READ_ROOT'] as $index => $name) {
            $id = $this->permissionId($index);
            $this->permissions->add(Permission::define($id, PermissionName::fromString($name), $this->now()));
            $this->agent = $this->agent->grantPermission($id, $this->now());
        }

        $this->agents = $this->createStub(AgentRepository::class);
        $this->agents->method('getByCredentialId')->willReturnCallback($this->agentByCredential(...));
        $this->agents->method('getById')->willReturnCallback($this->currentAgent(...));
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agents, $this->unitOfWork);
    }

    /**
     * @param list<McpSkillRevision> $revisions
     * @param list<McpSkillRevision> $concealed
     * @return array{McpResourceDiscovery, McpSkillDiscovery, McpSkillResources}
     */
    private function composition(
        array $revisions,
        bool $preResolve = false,
        bool $invalidSignature = false,
        ?AgentCredentialId $credential = null,
        string $secret = 'fixture-secret',
        string $correlationId = 'skill-conformance',
        bool $unmapped = false,
        ?McpResourceReadLimits $readLimits = null,
        ?McpSkillDiscoveryLimits $skillLimits = null,
        array $concealed = [],
        bool $unmappedMember = false
    ): array {
        $catalog = new AgentSkillPermissionCatalog(array_map(
            static fn(McpSkillRevision $revision): AgentSkillPermissionBinding => new AgentSkillPermissionBinding(
                $revision,
                in_array($revision, $concealed, true) ? ['UNGRANTED_SKILL'] : ['VIEW_SKILLS', 'READ_SKILLS']
            ),
            $unmapped ? [] : $revisions
        ));
        [$provider, $request] = $this->authority($invalidSignature, $credential, $secret);
        if ($preResolve) {
            $provider->resolve($request, $correlationId);
        }

        $availability = new AgentSkillAvailability($catalog, $provider, $request, $correlationId);
        $streams = $this->createStub(StreamFactoryInterface::class);
        $streams->method('createStream')->willReturnCallback($this->stream(...));
        $skillResources = new McpSkillResources(
            $unmapped ? $revisions : $catalog->revisions(),
            $availability,
            $streams
        );
        $bindings = [];
        foreach ($revisions as $revision) {
            foreach (array_keys($revision->resources()) as $index => $uri) {
                if ($unmappedMember && $index === 1) {
                    continue;
                }

                $bindings[] = new AgentResourcePermissionBinding(
                    $skillResources,
                    $uri,
                    [['READ_ASSETS', 'READ_GUIDES', 'READ_ROOT'][$index]]
                );
            }
        }

        $resourceCatalog = new AgentResourcePermissionCatalog($bindings);
        $resources = new McpResourceDiscovery(
            $resourceCatalog->providers(),
            new AgentResourceAvailability($resourceCatalog, $provider, $request, $correlationId),
            str_repeat('k', 32),
            'skills',
            readLimits: $readLimits ?? new McpResourceReadLimits()
        );
        $skills = new McpSkillDiscovery(
            $skillResources,
            $resources,
            str_repeat('k', 32),
            'skills',
            $skillLimits ?? new McpSkillDiscoveryLimits(pageSize: 1)
        );

        return [$resources, $skills, $skillResources];
    }

    /** @return array{CurrentAgentPrincipalProvider, SignedAgentRequest} */
    private function authority(
        bool $invalidSignature = false,
        ?AgentCredentialId $credential = null,
        string $secret = 'fixture-secret'
    ): array {
        ++$this->requestNumber;
        $request = new SignedAgentRequest(
            'POST',
            'mcp.example',
            '/mcp',
            '',
            $this->now(),
            'skill-nonce-'.$this->requestNumber,
            $credential ?? $this->credentialId(),
            'HMAC-SHA256',
            'valid',
            null,
            ''
        );
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
        if ($invalidSignature) {
            $request = new SignedAgentRequest(
                'POST',
                'mcp.example',
                '/mcp',
                '',
                $this->now(),
                'skill-nonce-'.$this->requestNumber,
                $credential ?? $this->credentialId(),
                'HMAC-SHA256',
                'invalid',
                null,
                ''
            );
        }

        return [$provider, $request];
    }

    /**
     * @param array{McpResourceDiscovery, McpSkillDiscovery, McpSkillResources} $composition
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function response(array $composition, string $method, array $parameters = [], bool $failure = false): array
    {
        $diagnostics = $this->createMock(McpDiagnostics::class);
        $diagnostics->expects(self::exactly($failure ? 1 : 0))->method('record');
        $responder = new McpResponder(
            new McpCapabilityRegistry(new McpServerInfo('skill-conformance', '1'), [$composition[0], $composition[1]]),
            diagnostics: $diagnostics
        );
        $wire = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => [...$parameters, '_meta' => [
                McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([
                    'extensions' => [McpSkillDiscovery::EXTENSION => StrictJson::fromObject([])]
                ])
            ]]
        ], JSON_THROW_ON_ERROR);

        return json_decode($responder->respond($wire)->toJson(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{McpResourceDiscovery, McpSkillDiscovery, McpSkillResources} $composition
     * @return array<string, mixed>
     */
    private function unknown(array $composition, string $method = 'resources/read'): array
    {
        return $this->response($composition, $method, ['uri' => 'skill://catalog/unknown/work/SKILL.md']);
    }

    /** @return array<string, mixed> */
    private function entry(McpSkillRevision $revision): array
    {
        return json_decode(json_encode($revision->entry(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function stream(string $bytes): StreamInterface
    {
        $this->opened[] = $bytes;
        $offset = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('tell')->willReturn(0);
        $stream->method('read')->willReturnCallback(static function (int $length) use ($bytes, &$offset): string {
            $chunk = substr($bytes, $offset, $length);
            $offset += strlen($chunk);

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
        return new DateTimeImmutable('2026-10-27T12:00:00+00:00');
    }
}
