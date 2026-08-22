<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserRoleAssignmentTest extends TestCase
{
    public function test_new_users_have_no_role_assignments_at_the_revision_baseline(): void
    {
        $user = $this->pendingUser();

        self::assertSame([], $user->getRoleIds());
        self::assertSame(0, $user->getAuthorizationAssignmentRevision());
    }

    public function test_persisted_role_assignments_and_revision_can_be_reconstituted_extensibly(): void
    {
        $roleId = RoleId::generate();
        $equalRoleId = RoleId::fromString($roleId->toString());

        $user = UserFixture::withRoleAssignments([$roleId, $equalRoleId], 7);

        self::assertCount(1, $user->getRoleIds());
        self::assertTrue($user->getRoleIds()[0]->equals($roleId));
        self::assertSame(7, $user->getAuthorizationAssignmentRevision());
    }

    public function test_role_assignment_replacement_is_value_deduplicated_and_returned_as_an_isolated_snapshot(): void
    {
        $user = $this->pendingUser();
        $roleId = RoleId::generate();
        $equalRoleId = RoleId::fromString($roleId->toString());

        $user->replaceRoleAssignments([$roleId, $equalRoleId]);

        $roleIds = $user->getRoleIds();
        $roleIds[] = RoleId::generate();
        unset($roleIds[0]);

        self::assertCount(1, $user->getRoleIds());
        self::assertTrue($user->hasRole($equalRoleId));
        self::assertSame(1, $user->getAuthorizationAssignmentRevision());
    }

    public function test_replacing_the_final_set_advances_only_the_authorization_assignment_revision_once(): void
    {
        $user = $this->pendingUser();
        $firstRoleId = RoleId::generate();
        $secondRoleId = RoleId::generate();
        $user->replaceRoleAssignments([$firstRoleId]);
        $authenticationVersion = $user->getAuthenticationVersion();
        $authenticationAuthorityRevision = $user->getAuthenticationAuthorityRevision();

        $user->replaceRoleAssignments([$secondRoleId, RoleId::fromString($secondRoleId->toString())]);

        self::assertFalse($user->hasRole($firstRoleId));
        self::assertTrue($user->hasRole($secondRoleId));
        self::assertSame(2, $user->getAuthorizationAssignmentRevision());
        self::assertSame($authenticationVersion, $user->getAuthenticationVersion());
        self::assertSame($authenticationAuthorityRevision, $user->getAuthenticationAuthorityRevision());
    }

    public function test_replacing_with_an_equal_set_is_a_no_op(): void
    {
        $user = $this->pendingUser();
        $firstRoleId = RoleId::generate();
        $secondRoleId = RoleId::generate();
        $user->replaceRoleAssignments([$firstRoleId, $secondRoleId]);

        $user->replaceRoleAssignments([$secondRoleId, RoleId::fromString($firstRoleId->toString())]);

        self::assertSame(1, $user->getAuthorizationAssignmentRevision());
        self::assertTrue($user->hasRole($firstRoleId));
        self::assertTrue($user->hasRole($secondRoleId));
    }

    public function test_cloned_users_own_independent_role_assignment_sets(): void
    {
        $user = $this->pendingUser();
        $existingRoleId = RoleId::generate();
        $replacementRoleId = RoleId::generate();
        $user->replaceRoleAssignments([$existingRoleId]);
        $replacement = clone $user;

        $replacement->replaceRoleAssignments([$replacementRoleId]);

        self::assertTrue($user->hasRole($existingRoleId));
        self::assertFalse($user->hasRole($replacementRoleId));
        self::assertFalse($replacement->hasRole($existingRoleId));
        self::assertTrue($replacement->hasRole($replacementRoleId));
    }

    public function test_authentication_mutations_preserve_role_assignments_and_their_revision(): void
    {
        $user = $this->pendingUser();
        $roleId = RoleId::generate();
        $user->replaceRoleAssignments([$roleId]);
        $authorizationAssignmentRevision = $user->getAuthorizationAssignmentRevision();

        $user->activate($this->passwordHash('initial-password'));
        $user->rehashPassword($this->passwordHash('rehash-password'));
        $user->changePassword($this->passwordHash('changed-password'));
        $user->resetPassword($this->passwordHash('reset-password'));
        $user->advanceAuthenticationAuthorityRevision();

        self::assertSame([$roleId], $user->getRoleIds());
        self::assertSame($authorizationAssignmentRevision, $user->getAuthorizationAssignmentRevision());
    }

    private function passwordHash(string $password): PasswordHash
    {
        return PasswordHash::fromString(password_hash($password, PASSWORD_DEFAULT));
    }

    private function pendingUser(): User
    {
        return User::invite(
            UserId::generate(),
            EmailAddress::fromString('role-assignment@example.test')
        );
    }
}
