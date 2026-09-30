<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agent::class)]
#[CoversClass(AgentView::class)]
#[CoversClass(GetAgentByIdHandler::class)]
#[CoversClass(ListAgentsHandler::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(CurrentAgentPrincipalProvider::class)]
final class AgentHydrationTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function states(): iterable
    {
        yield 'active' => [false];
        yield 'revoked' => [true];
    }

    #[DataProvider('states')]
    public function test_hydration_preserves_reads_authentication_and_original_issuance(bool $revoked): void
    {
        $env = new ProvisioningEnvironment();
        $issuance = $env->service()->provision($env->key, $env->request)->getIssuance();
        self::assertNotNull($issuance);
        $clock = new FixedClock($issuance->getIssuedAt());
        if ($revoked) {
            new AgentCredentialLifecycleService(
                $env->agents,
                $env->audit,
                $clock,
                $env->transaction,
                $env->events
            )->revoke('maintainer-42', $issuance->getAgentId());
        }

        $stored = $env->agents->all()[0];
        $hydrated = Agent::reconstitute(
            $stored->getId(),
            $stored->getName(),
            $stored->getState(),
            $stored->getCredentialId(),
            $stored->getCredentialRevision(),
            $stored->getEncryptedHmacSharedSecretEnvelope(),
            $stored->getPermissionIds(),
            $stored->getPermissionAssignmentRevision(),
            $stored->getCreatedAt(),
            $stored->getUpdatedAt()
        );
        self::assertNotSame($stored, $hydrated);
        self::assertEquals($stored, $hydrated);
        $agents = new InMemoryAgentRepository($env->transaction, operations: $env->operations);
        $agents->add($hydrated);

        $env->operations->agents = $agents;
        $operations = $env->operations->operations;
        $audit = $env->audit->all();
        $events = $env->events->events();
        $transactions = $env->transaction->transactions;
        $permissions = new InMemoryPermissionRepository();
        $view = new GetAgentByIdHandler($agents, $permissions)->handle(
            QueryMessage::create(new GetAgentById($stored->getId()))
        );
        self::assertSame(AgentView::fromAgent($stored, [])->toArray(), $view->toArray());
        $list = new ListAgentsHandler($agents, $permissions)->handle(
            QueryMessage::create(new ListAgents(new Pagination()))
        );
        self::assertSame($view->toArray(), $list->records()->get(0)->toArray());
        $status = new GetAgentOperationHandler($env->operations, $env->authorization)->handle(
            QueryMessage::create(new GetAgentOperation($env->key, $env->request->getDestination()))
        );
        self::assertTrue($status->isConfirmed());
        self::assertSame($issuance, $status->getIssuance());
        $request = new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/agents',
            '',
            $clock->now(),
            'hydrated-nonce',
            $stored->getCredentialId(),
            'HMAC-SHA256',
            'valid-signature',
            null,
            ''
        );
        $authentication = new InMemoryUnitOfWork();
        $nonces = new InMemoryAgentRequestNonceConsumer($agents, $authentication);
        $provider = new CurrentAgentPrincipalProvider(
            $agents,
            $permissions,
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'original-test-secret'),
            $clock,
            $nonces,
            $authentication
        );
        try {
            $principal = $provider->resolve($request, 'hydrated-authority');
            self::assertFalse($revoked);
            self::assertSame($stored->getId()->toString(), $principal->toArray()['agent_id']);
            self::assertSame($stored->getCredentialRevision(), $principal->toArray()['credential_revision']);
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertTrue($revoked);
        }

        self::assertSame($revoked ? 0 : 1, $nonces->consumptionCalls());
        self::assertSame([$hydrated], $agents->all());
        self::assertSame($operations, $env->operations->operations);
        self::assertSame($audit, $env->audit->all());
        self::assertSame($events, $env->events->events());
        self::assertSame($transactions, $env->transaction->transactions);
        self::assertSame(1, $env->generations);
        $safe = serialize([$view, $status, $list->records()->get(0)]);
        self::assertStringNotContainsString('original-test-secret', $safe);
        self::assertStringNotContainsString('auth-envelope:', $safe);
    }
}
