<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Type\Arrayable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(GetAgentProfile::class)]
#[CoversClass(AgentProfileView::class)]
final class AgentProfileTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'missing target' => [[]];
        yield 'null target' => [['agent_id' => null]];
        yield 'malformed target' => [['agent_id' => 'not-an-agent-id']];
    }

    public function test_query_round_trips_the_explicit_target(): void
    {
        $id = AgentId::fromString('018f0000-0000-7000-8000-000000000070');
        $query = new GetAgentProfile($id);

        self::assertInstanceOf(Query::class, $query);
        self::assertSame($id, $query->getAgentId());
        self::assertSame(['agent_id' => '018f0000-0000-7000-8000-000000000070'], $query->toArray());
        self::assertEquals($query, GetAgentProfile::fromArray($query->toArray()));
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidQueries')]
    public function test_query_rejects_a_missing_or_invalid_target(array $data): void
    {
        $this->expectException(DomainException::class);

        GetAgentProfile::fromArray($data);
    }

    public function test_immutable_result_exposes_only_identity_and_name(): void
    {
        $id = AgentId::fromString('018f0000-0000-7000-8000-000000000070');
        $name = AgentName::fromString('Deployment worker');
        $view = new AgentProfileView($id, $name);

        self::assertInstanceOf(Arrayable::class, $view);
        self::assertSame($id, $view->getAgentId());
        self::assertSame($name, $view->getName());
        self::assertSame(
            ['agent_id' => '018f0000-0000-7000-8000-000000000070', 'name' => 'Deployment worker'],
            $view->toArray()
        );
        $reflection = new ReflectionClass($view);
        self::assertTrue($reflection->isReadOnly());
        self::assertSame(
            ['agentId', 'name'],
            array_map(
                static fn(ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties()
            )
        );
    }
}
