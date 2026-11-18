<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordResetRevocationService::class)]
final class PasswordResetRevocationServiceTest extends TestCase
{
    public function test_wrong_owner_is_rejected_before_both_revocation_and_terminal_no_work(): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $grant = PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('fixture'),
            $at,
            $at->modify('+1 hour'),
            EmailAddress::fromString('fixture@example.test'),
            'encrypted',
            new RecoveryAuthority(1, 1)
        );
        foreach ([$grant, $grant->revoke($at)] as $returned) {
            $repository = $this->createMock(PasswordResetGrantRepository::class);
            $repository->method('getLatestByUserId')->willReturn($returned);
            $repository->expects(self::never())->method('replace');
            try {
                new PasswordResetRevocationService($repository)->revokeFor(UserId::generate(), $at);
                self::fail('An unrelated generation was accepted.');
            } catch (LogicException $exception) {
                self::assertSame(
                    "The credential repository returned another User's generation.",
                    $exception->getMessage()
                );
            }
        }
    }
}
