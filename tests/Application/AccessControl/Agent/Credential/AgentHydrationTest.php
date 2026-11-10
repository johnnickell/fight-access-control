<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
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
        $issuance = $env->bus()->dispatchAndRead(new ProvisionAgent($env->key, $env->request))->getIssuance();
        self::assertNotNull($issuance);
        $clock = new FixedClock($issuance->getIssuedAt());
        if ($revoked) {
            new AgentCredentialLifecycleService(
                $env->agentRepository,
                $env->auditEvidenceRepository,
                $clock,
                $env->transaction,
                $env->eventDispatcher
            )->revoke(UserId::generate(), $issuance->getAgentId());
        }

        $stored = $env->agentRepository->all()[0];
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
        $agentRepository = new InMemoryAgentRepository(
            $env->transaction,
            operationRepository: $env->operationRepository
        );
        $agentRepository->add($hydrated);

        $env->operationRepository->agentRepository = $agentRepository;
        $operations = $env->operationRepository->operations;
        $audit = $env->auditEvidenceRepository->all();
        $events = $env->eventDispatcher->events();
        $transactions = $env->transaction->transactions;
        $permissionRepository = new InMemoryPermissionRepository();
        $view = new GetAgentByIdHandler($agentRepository, $permissionRepository)->handle(
            QueryMessage::create(new GetAgentById($stored->getId()))
        );
        self::assertSame(AgentView::fromAgent($stored, [])->toArray(), $view->toArray());
        $list = new ListAgentsHandler($agentRepository, $permissionRepository)->handle(
            QueryMessage::create(new ListAgents(new Pagination()))
        );
        self::assertSame($view->toArray(), $list->records()->get(0)->toArray());
        $status = new GetAgentOperationHandler($env->operationRepository, $env->operationAuthorization)->handle(
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
        $nonces = new InMemoryAgentRequestNonceConsumer($agentRepository, $authentication);
        $provider = new CurrentAgentPrincipalProvider(
            $agentRepository,
            $permissionRepository,
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
        self::assertSame([$hydrated], $agentRepository->all());
        self::assertSame($operations, $env->operationRepository->operations);
        self::assertSame($audit, $env->auditEvidenceRepository->all());
        self::assertSame($events, $env->eventDispatcher->events());
        self::assertSame($transactions, $env->transaction->transactions);
        self::assertSame(1, $env->generations);
        $safe = serialize([$view, $status, $list->records()->get(0)]);
        self::assertStringNotContainsString('original-test-secret', $safe);
        self::assertStringNotContainsString('auth-envelope:', $safe);
    }
}
