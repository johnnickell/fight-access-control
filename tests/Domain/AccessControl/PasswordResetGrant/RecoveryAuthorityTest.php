<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetGrantException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoveryAuthority::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(User::class)]
final class RecoveryAuthorityTest extends TestCase
{
    public function test_security_values_are_bounded_and_compared_independently(): void
    {
        $authority = new RecoveryAuthority(2, 3);
        self::assertSame(2, $authority->getAuthenticationVersion());
        self::assertSame(3, $authority->getCanonicalEmailRevision());
        self::assertTrue($authority->equals(new RecoveryAuthority(2, 3)));
        self::assertFalse($authority->equals(new RecoveryAuthority(3, 3)));
        self::assertFalse($authority->equals(new RecoveryAuthority(2, 4)));
        self::assertTrue(
            new RecoveryAuthority(PHP_INT_MAX, PHP_INT_MAX)->equals(new RecoveryAuthority(PHP_INT_MAX, PHP_INT_MAX))
        );
        foreach ([[0, 0], [-1, 0], [1, -1]] as [$version, $revision]) {
            $caught = null;
            try {
                new RecoveryAuthority($version, $revision);
            } catch (DomainException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught);
        }
    }

    public function test_hydration_validates_complete_generation_ownership_and_terminal_material(): void
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $grant = PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('fixture'),
            $at,
            $at->modify('+1 hour'),
            EmailAddress::fromString('recovery@example.test'),
            'fixture:encrypted',
            new RecoveryAuthority(1, 0)
        );
        foreach (['revision', 'digest', 'owner', 'expiry', 'terminal', 'material'] as $invalid) {
            $caught = null;
            try {
                ExtensiblePasswordResetGrant::reconstitute(
                    $grant->getId(),
                    $invalid === 'owner' ? UserId::generate() : $grant->getUserId(),
                    $invalid === 'digest' ? 'invalid' : $grant->getCredentialHash(),
                    $invalid === 'expiry' ? $at : $grant->getExpiresAt(),
                    $grant->getDelivery(),
                    $grant->getAuthority(),
                    in_array($invalid, ['terminal', 'material'], true) ? $at : null,
                    $invalid === 'terminal' ? $at : null,
                    $invalid === 'revision' ? -1 : 0
                );
            } catch (PasswordResetGrantException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught);
        }
    }
}
