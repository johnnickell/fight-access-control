<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class RegistrationCreationContractTest extends TestCase
{
    public function test_creation_reference_validation_requires_exact_complete_pending_assignment_set(): void
    {
        foreach (['empty', 'incomplete', 'duplicate', 'unassigned', 'active'] as $case) {
            $fixture = new RegistrationFixture();
            $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
            $command = $fixture->command();
            $user = User::invite($command->getUserId(), $command->getEmail(), $at);
            $user->assignRole($fixture->member->getId(), $at);
            $user->assignRole($fixture->reader->getId(), $at);
            $expected = [$fixture->member, $fixture->reader];
            if ($case === 'empty') {
                $expected = [];
            } elseif ($case === 'incomplete') {
                $expected = [$fixture->member];
            } elseif ($case === 'duplicate') {
                $expected[] = $fixture->member;
            } elseif ($case === 'unassigned') {
                $user = User::invite($command->getUserId(), $command->getEmail(), $at);
            } else {
                $user = UserFixture::withState('alice@example.test', UserState::ACTIVE);
            }

            self::assertFalse($fixture->unitOfWork->commitTransactional(
                fn(): bool => $fixture->users->addWithRoleAssignments($user, $expected)
            ));
            self::assertSame([], $fixture->users->all());
        }
    }

    public function test_equivalent_reconstituted_role_expectations_are_not_object_identity_requirements(): void
    {
        $fixture = new RegistrationFixture();
        $command = $fixture->command();
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $user = User::invite($command->getUserId(), $command->getEmail(), $at);
        $user->assignRole($fixture->member->getId(), $at);
        self::assertTrue($fixture->unitOfWork->commitTransactional(
            fn(): bool => $fixture->users->addWithRoleAssignments($user, [clone $fixture->member])
        ));
        self::assertSame([$user], $fixture->users->all());
    }

    public function test_plain_add_cannot_bypass_initial_role_reference_validation(): void
    {
        $fixture = new RegistrationFixture();
        $command = $fixture->command();
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $user = User::invite($command->getUserId(), $command->getEmail(), $at);
        $user->assignRole($fixture->member->getId(), $at);
        self::assertTrue($fixture->roles->remove($fixture->member));
        $this->expectException(RuntimeException::class);
        try {
            $fixture->unitOfWork->commitTransactional(fn() => $fixture->users->add($user));
        } finally {
            self::assertSame([], $fixture->users->all());
        }
    }

    public function test_stable_user_identity_cannot_be_reused_for_another_email(): void
    {
        $fixture = new RegistrationFixture();
        $command = $fixture->command();
        $fixture->handler()->handle(CommandMessage::create($command));
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $differentEmail = $fixture->command('other@example.test')->getEmail();
        $user = User::invite($command->getUserId(), $differentEmail, $at);
        $user->assignRole($fixture->member->getId(), $at);
        $this->expectException(RuntimeException::class);
        try {
            $fixture->unitOfWork->commitTransactional(fn(): bool => $fixture->users->addWithRoleAssignments(
                $user,
                [$fixture->member]
            ));
        } finally {
            self::assertCount(1, $fixture->users->all());
            self::assertSame($command->getEmail(), $fixture->users->all()[0]->getEmail());
            self::assertCount(1, $fixture->grants->all());
            self::assertCount(1, $fixture->audit->all());
        }
    }
}
