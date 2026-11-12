<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Subscription\Agent;

use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionFamilies;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentTaskSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriptionScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosurePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use Fight\Common\Application\Mcp\Resource\McpReadableResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Application\Mcp\Skill\McpSkillAvailability;
use Fight\Common\Application\Mcp\Skill\McpSkillResources;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionAdmission;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent\Fixture\PromptProvider;
use Fight\Test\AccessControl\Adapter\Mcp\Resource\Agent\Fixture\ResourceProvider;
use Fight\Test\AccessControl\Adapter\Mcp\Skill\Agent\Fixture\SkillRevision;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProtectedTool;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;

#[CoversClass(AgentSubscriptionFamilies::class)]
#[CoversClass(AgentSubscriptionAdmission::class)]
#[CoversClass(AgentTaskSubscriptionAdmission::class)]
final class AgentSubscriptionUnitTest extends TestCase
{
    public function test_optional_tools_and_prompts_use_only_metadata_and_current_direct_permissions(): void
    {
        $fixture = new TaskAdmissionFixture(['VIEW_AGENTS', 'UPDATE_AGENTS', 'PROMPT']);
        $tool = new ProtectedTool(static function (): never {
            self::fail('Availability must not execute Tools.');
        });
        $registry = new McpToolRegistry([$tool]);
        $prompt = new PromptProvider(McpPromptInfo::fromArray(['name' => 'prompt']), static function (): never {
            self::fail('Availability must not get Prompt content.');
        });
        $families = new AgentSubscriptionFamilies(
            $registry,
            new AgentToolPermissionCatalog($registry),
            promptCatalog: new AgentPromptPermissionCatalog([new AgentPromptPermissionBinding($prompt, ['PROMPT'])])
        );
        $principal = $fixture->provider->resolve($fixture->request, 'families');
        self::assertTrue($families->allows($principal, 'toolsListChanged', null));
        self::assertTrue($families->allows($principal, 'promptsListChanged', null));
        self::assertFalse($families->allows($principal, 'promptsListChanged', 'forged'));
        self::assertFalse(new AgentSubscriptionFamilies()->allows($principal, 'toolsListChanged', null));
        self::assertFalse(new AgentSubscriptionFamilies()->allows($principal, 'promptsListChanged', null));
        self::assertFalse(new AgentSubscriptionFamilies()->allows($principal, 'resourcesListChanged', null));
        self::assertFalse($families->allows($this->principal([]), 'toolsListChanged', null));
        self::assertFalse($families->allows($this->principal([]), 'promptsListChanged', null));
    }

    public function test_tool_registry_and_permission_catalog_must_be_paired(): void
    {
        $this->expectException(LogicException::class);

        new AgentSubscriptionFamilies(toolRegistry: new McpToolRegistry([]));
    }

    public function test_plain_resources_are_metadata_only_but_unknown_protected_provider_is_not_authority(): void
    {
        $resource = McpResourceInfo::fromArray(['uri' => 'docs:saved', 'name' => 'Saved']);
        $provider = $this->createMock(McpReadableResourceProvider::class);
        $provider->method('find')->willReturnCallback(static fn(string $uri): ?McpResourceInfo =>
            $uri === 'docs:saved' ? $resource : null);
        $provider->method('resources')->willReturn([$resource]);
        $provider->expects(self::never())->method('open');
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($provider, 'docs:saved', ['RESOURCE'])
        ]);
        $families = new AgentSubscriptionFamilies(resourceCatalog: $catalog);
        $principal = $this->principal(['RESOURCE']);
        self::assertTrue($families->allows($principal, 'resourceSubscriptions', 'docs:saved'));
        self::assertTrue($families->allows($principal, 'resourcesListChanged', null));
        self::assertFalse($families->allows($principal, 'resourceSubscriptions', 'docs:absent'));
        self::assertFalse($families->allows($principal, 'unsupported', null));
        self::assertFalse($families->allows($principal, 'resourcesListChanged', 'docs:saved'));
        $unknown = new ResourceProvider(['docs:saved' => $resource->toArray()], static function (): never {
            self::fail('Protected Resource content must not be opened.');
        });
        $protected = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($unknown, 'docs:saved', ['RESOURCE'])
        ]);
        self::assertFalse(new AgentSubscriptionFamilies(resourceCatalog: $protected)->allows(
            $principal,
            'resourceSubscriptions',
            'docs:saved'
        ));
    }

    public function test_skill_availability_requires_whole_revision_and_each_member_without_opening(): void
    {
        $revision = SkillRevision::create();
        $skills = new AgentSkillPermissionCatalog([new AgentSkillPermissionBinding($revision, ['SKILL'])]);
        $availability = $this->createMock(McpSkillAvailability::class);
        $availability->expects(self::never())->method('isAvailable');
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams->expects(self::never())->method('createStream');
        $provider = new McpSkillResources($skills->revisions(), $availability, $streams);
        $bindings = [];
        foreach ($revision->resources() as $uri => $resource) {
            $bindings[] = new AgentResourcePermissionBinding($provider, $uri, ['MEMBER']);
        }

        $resources = new AgentResourcePermissionCatalog($bindings);
        $families = new AgentSubscriptionFamilies(resourceCatalog: $resources, skillCatalog: $skills);
        $uri = array_key_first($revision->resources());
        self::assertTrue($families->allows($this->principal(['SKILL', 'MEMBER']), 'resourceSubscriptions', $uri));
        self::assertFalse($families->allows($this->principal(['MEMBER']), 'resourceSubscriptions', $uri));
        self::assertFalse($families->allows($this->principal(['SKILL']), 'resourceSubscriptions', $uri));
        self::assertFalse(new AgentSubscriptionFamilies(resourceCatalog: $resources)->allows(
            $this->principal(['SKILL', 'MEMBER']),
            'resourceSubscriptions',
            $uri
        ));
        self::assertFalse(new AgentSubscriptionFamilies(
            resourceCatalog: new AgentResourcePermissionCatalog(array_slice($bindings, 0, 1)),
            skillCatalog: $skills
        )->allows($this->principal(['SKILL', 'MEMBER']), 'resourceSubscriptions', $uri));
        self::assertFalse(new AgentSubscriptionFamilies(
            resourceCatalog: $resources,
            skillCatalog: new AgentSkillPermissionCatalog([
                new AgentSkillPermissionBinding(SkillRevision::create('other'), ['SKILL'])
            ])
        )->allows($this->principal(['SKILL', 'MEMBER']), 'resourceSubscriptions', $uri));
    }

    /** @return iterable<string, array{string}> */
    public static function metadataFaults(): iterable
    {
        yield 'wrong selected identity' => ['wrong'];
        yield 'duplicate catalog identity' => ['duplicate'];
        yield 'metadata overflow' => ['overflow'];
    }

    #[DataProvider('metadataFaults')]
    public function test_dynamic_metadata_faults_never_borrow_foreign_or_partial_authority(string $fault): void
    {
        $resource = McpResourceInfo::fromArray(['uri' => 'docs:saved', 'name' => 'Saved']);
        $state = new class {
            public bool $changed = false;
        };
        $provider = $this->createStub(McpReadableResourceProvider::class);
        $provider->method('find')->willReturnCallback(
            static function () use ($state, $resource, $fault): McpResourceInfo {
                if ($state->changed && $fault === 'wrong') {
                    return McpResourceInfo::fromArray(['uri' => 'docs:wrong', 'name' => 'Wrong']);
                }

                return $resource;
            }
        );
        $provider->method('resources')->willReturnCallback(static function () use ($resource, $fault): iterable {
            if ($fault === 'duplicate') {
                yield $resource;
                yield $resource;
            } else {
                for ($i = 0; $i <= 10000; ++$i) {
                    yield McpResourceInfo::fromArray(['uri' => 'docs:'.$i, 'name' => 'Metadata']);
                }
            }
        });
        $resources = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($provider, 'docs:saved', ['RESOURCE'])
        ]);
        $state->changed = true;
        $this->expectException(LogicException::class);

        new AgentSubscriptionFamilies(resourceCatalog: $resources)->allows(
            $this->principal(['RESOURCE']),
            $fault === 'wrong' ? 'resourceSubscriptions' : 'resourcesListChanged',
            $fault === 'wrong' ? 'docs:saved' : null
        );
    }

    public function test_listening_and_disclosure_keep_their_own_current_permissions_and_scope(): void
    {
        $fixture = new TaskAdmissionFixture(['LISTEN', 'SIGNAL', 'VIEW_AGENTS', 'UPDATE_AGENTS']);
        $registry = new McpToolRegistry([new ProtectedTool(static function (): void {
        })]);
        $families = new AgentSubscriptionFamilies($registry, new AgentToolPermissionCatalog($registry));
        $scope = $this->createMock(AgentSubscriptionScope::class);
        $scope->expects(self::exactly(2))->method('allows')->willReturn(true);
        $admission = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority($fixture, 'LISTEN'),
            $this->authority($fixture, 'SIGNAL'),
            $scope,
            $families
        );
        self::assertSame('subscriber', $admission->subscriber());
        self::assertFalse($admission->allows('foreign', 'toolsListChanged', null, null));
        self::assertFalse($admission->allows('subscriber', 'resourceSubscriptions', null, null));
        self::assertTrue($admission->allows('subscriber', 'toolsListChanged', null, null));
        self::assertTrue($admission->allows('subscriber', 'toolsListChanged', null, $this->notice()));
        $missing = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority($fixture, 'MISSING'),
            $this->authority($fixture, 'SIGNAL'),
            $scope,
            $families
        );
        self::assertFalse($missing->allows('subscriber', 'toolsListChanged', null, null));
        $missing = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority($fixture, 'LISTEN'),
            $this->authority($fixture, 'MISSING'),
            $scope,
            $families
        );
        self::assertFalse($missing->allows('subscriber', 'toolsListChanged', null, $this->notice()));
    }

    public function test_disclosure_must_match_the_original_agent_binding(): void
    {
        $fixture = new TaskAdmissionFixture(['LISTEN']);
        $other = new TaskAdmissionFixture(['SIGNAL'], identity: 901);
        $admission = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority($fixture, 'LISTEN'),
            $this->authority($other, 'SIGNAL'),
            $this->createStub(AgentSubscriptionScope::class),
            new AgentSubscriptionFamilies()
        );
        $this->expectException(LogicException::class);

        $admission->allows('subscriber', 'toolsListChanged', null, $this->notice());
    }

    public function test_assignment_change_between_listening_and_disclosure_does_not_reuse_the_old_allow(): void
    {
        $fixture = new TaskAdmissionFixture(['LISTEN', 'SIGNAL']);
        $repository = new InMemoryAgentRepository();
        $listen = $fixture->permissionRepository->getByName(PermissionName::fromString('LISTEN'));
        $repository->add($fixture->agent->revokePermission($listen->getId(), TaskAdmissionFixture::now()));
        $disclosure = new AgentNoticeAuthority(
            AgentContinuationBinding::fromRequest($fixture->provider, $fixture->request, 'unit-change'),
            $repository,
            $fixture->permissionRepository,
            new FixedClock(TaskAdmissionFixture::now()),
            [PermissionName::fromString('SIGNAL')]
        );
        $scope = $this->createMock(AgentSubscriptionScope::class);
        $scope->expects(self::never())->method('allows');
        $admission = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority($fixture, 'LISTEN'),
            $disclosure,
            $scope,
            new AgentSubscriptionFamilies()
        );

        self::assertFalse($admission->allows('subscriber', 'toolsListChanged', null, $this->notice()));
    }

    public function test_task_signals_are_independent_and_generic_families_keep_exact_subscriber_binding(): void
    {
        $fixture = new TaskAdmissionFixture(['TASK_SIGNAL']);
        $policy = $this->createMock(AgentTaskDisclosurePolicy::class);
        $policy->expects(self::once())->method('allowsSignal')
            ->with(self::anything(), 'subscriber', TaskId::fromString(TaskAdmissionFixture::ID))->willReturn(true);
        $generic = $this->createStub(McpSubscriptionAdmission::class);
        $generic->method('subscriber')->willReturn('subscriber');
        $generic->method('allows')->willReturn(true);
        $admission = new AgentTaskSubscriptionAdmission(
            'subscriber',
            $this->authority($fixture, 'TASK_SIGNAL'),
            $policy,
            $generic
        );
        self::assertSame('subscriber', $admission->subscriber());
        self::assertFalse($admission->allows('other', 'taskIds', TaskAdmissionFixture::ID, null));
        self::assertFalse($admission->allows('subscriber', 'taskIds', 'invalid', null));
        self::assertFalse($admission->allows('subscriber', 'taskIds', TaskAdmissionFixture::ID, $this->notice()));
        self::assertTrue($admission->allows('subscriber', 'toolsListChanged', null, null));
        self::assertTrue($admission->allows('subscriber', 'taskIds', TaskAdmissionFixture::ID, null));
    }

    /** @param list<string> $grants */
    private function principal(array $grants): AuthenticatedAgentPrincipal
    {
        $fixture = new TaskAdmissionFixture($grants);

        return $fixture->provider->resolve($fixture->request, 'family-unit');
    }

    private function authority(TaskAdmissionFixture $fixture, string $permission): AgentNoticeAuthority
    {
        return new AgentNoticeAuthority(
            AgentContinuationBinding::fromRequest($fixture->provider, $fixture->request, 'subscription-unit'),
            $fixture->agentRepository,
            $fixture->permissionRepository,
            new FixedClock(TaskAdmissionFixture::now()),
            [PermissionName::fromString($permission)]
        );
    }

    private function notice(): Notice
    {
        return Notice::fromArray([
            'version'       => 1,
            'id'            => 'notice',
            'producer'      => 'producer',
            'scope'         => 'scope',
            'topic'         => 'topic',
            'type'          => 'changed',
            'occurred_at'   => null,
            'metadata'      => [],
            'relationships' => [
                'subject'    => null,
                'recipients' => [],
                'author'     => null,
                'command'    => null,
                'event'      => null,
                'action'     => null
            ]
        ]);
    }
}
