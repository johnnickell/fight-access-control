<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler\RegistrationFixture;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoveCustomRoleHandler::class)]
final class RoleLifecycleAuditReferenceTest extends TestCase
{
    public function test_removal_winner_commits_evidence_and_aborts_resolved_registration(): void
    {
        $fixture = null;
        $audit = null;
        $fixture = new RegistrationFixture(beforeInsert: function () use (&$fixture, &$audit): void {
            $transaction = new InMemoryUnitOfWork();
            $audit = new InMemoryAuditEvidenceRepository($transaction);
            $this->remove($fixture, $transaction, $audit);
        });
        try {
            $fixture->handler()->handle(CommandMessage::create($fixture->command()));
            self::fail('Registration accepted a removed Role.');
        } catch (StateConflict) {
            self::assertNull($fixture->roleRepository->getById($fixture->member->getId()));
            self::assertCount(1, $audit->all());
            self::assertEquals($fixture->member->getRevision(), $audit->all()[0]->getFacts()->getBefore());
            self::assertNull($audit->all()[0]->getFacts()->getAfter());
            self::assertSame([], $fixture->userRepository->all());
            self::assertSame([], $fixture->activationGrantRepository->all());
            self::assertSame([], $fixture->auditEvidenceRepository->all());
        }
    }

    public function test_registration_winner_blocks_removal_during_and_after_commit_without_removal_evidence(): void
    {
        $fixture = new RegistrationFixture();
        $transaction = new InMemoryUnitOfWork();
        $audit = new InMemoryAuditEvidenceRepository($transaction);
        $fixture->unitOfWork->beforeCommit = function () use ($fixture, $transaction, $audit): void {
            try {
                $this->remove($fixture, $transaction, $audit);
                self::fail('Removal bypassed the registration reference fence.');
            } catch (RuleViolation | StateConflict) {
                self::assertSame([], $audit->all());
            }
        };
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        try {
            $this->remove($fixture, $transaction, $audit);
            self::fail('Removal accepted a committed pending-registration reference.');
        } catch (RuleViolation) {
            self::assertSame($fixture->member, $fixture->roleRepository->getById($fixture->member->getId()));
            self::assertCount(1, $fixture->userRepository->all());
            self::assertCount(1, $fixture->activationGrantRepository->all());
            self::assertCount(1, $fixture->auditEvidenceRepository->all());
            self::assertSame([], $audit->all());
        }
    }

    private function remove(
        RegistrationFixture $fixture,
        InMemoryUnitOfWork $transaction,
        InMemoryAuditEvidenceRepository $audit
    ): void {
        $handler = new RemoveCustomRoleHandler(
            $fixture->competingRoles($transaction),
            new InMemoryUserRepository(
                $transaction,
                state: $fixture->userState,
                authorizationReferences: $fixture->unitOfWork->authorizationReferenceState()
            ),
            new FixedClock('2030-01-08T00:00:00Z'),
            $transaction,
            new InMemoryEventDispatcher(),
            $audit
        );
        $handler->handle(CommandMessage::create(new RemoveCustomRole(
            UserId::generate(),
            $fixture->member->getId(),
            $fixture->member->getRevision()
        )));
    }
}
