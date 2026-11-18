<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\RefreshSession;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RefreshSession::class)]
final class RefreshSessionTypeContractTest extends TestCase
{
    /** @phpstan-return iterable<string, array{array<string, mixed>}> */
    public static function invalidHydration(): iterable
    {
        yield 'zero authentication version' => [['authenticationVersion' => 0]];
        yield 'negative authentication version' => [['authenticationVersion' => -1]];
        yield 'negative revision' => [['revision' => -1]];
        yield 'invalid current digest' => [['credentialDigest' => 'not-a-digest']];
        yield 'invalid history digest' => [['usedCredentialDigests' => ['not-a-digest']]];
        yield 'wrong history type' => [['usedCredentialDigests' => [42]]];
        yield 'non-list history' => [['usedCredentialDigests' => ['latest' => hash('sha256', 'previous')]]];
        yield 'current credential already used' => [['usedCredentialDigests' => [hash('sha256', str_repeat('a', 64))]]];
        $previousDigest = hash('sha256', 'previous');
        yield 'duplicate history' => [['usedCredentialDigests' => [$previousDigest, $previousDigest]]];
        yield 'history without rotation time' => [['usedCredentialDigests' => [hash('sha256', 'previous')]]];
        yield 'rotation time without history' => [['rotatedAt' => new DateTimeImmutable('2026-11-05T00:00:00+00:00')]];
        yield 'activity before creation' => [['lastActivityAt' => new DateTimeImmutable('2026-11-04T00:00:00+00:00')]];
        yield 'idle expiry at activity' => [['idleExpiresAt' => new DateTimeImmutable('2026-11-05T00:00:00+00:00')]];
        $beyondAbsolute = new DateTimeImmutable('2026-11-08T00:00:00+00:00');
        yield 'idle expiry beyond absolute' => [['idleExpiresAt' => $beyondAbsolute]];
        yield 'mismatched rotation time' => [[
            'usedCredentialDigests' => [hash('sha256', 'previous')],
            'rotatedAt'             => new DateTimeImmutable('2026-11-05T01:00:00+00:00')
        ]];
    }

    /** @phpstan-param array<string, mixed> $overrides */
    #[DataProvider('invalidHydration')]
    public function test_hydration_rejects_invalid_current_authority_shapes(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::hydrate($overrides);
    }

    public function test_start_rejects_an_invalid_authentication_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable('2026-11-05T00:00:00+00:00'),
            new DateTimeImmutable('2026-11-06T00:00:00+00:00'),
            new DateTimeImmutable('2026-11-07T00:00:00+00:00'),
            0,
            false
        );
    }

    public function test_current_complete_hydration_retains_rotation_history_and_replay_boundaries(): void
    {
        $previous = RefreshCredential::fromString(str_repeat('b', 64));
        $session = self::hydrate([
            'usedCredentialDigests' => [$previous->digest()],
            'lastActivityAt'        => new DateTimeImmutable('2026-11-05T01:00:00+00:00'),
            'rotatedAt'             => new DateTimeImmutable('2026-11-05T01:00:00+00:00'),
            'revision'              => 7,
            'authenticationVersion' => 4
        ]);
        self::assertSame(7, $session->getRevision());
        self::assertSame(4, $session->getAuthenticationVersion());
        self::assertTrue($session->matchesCredential(RefreshCredential::fromString(str_repeat('a', 64))));
        self::assertTrue($session->matchesUsedCredential($previous));
        self::assertTrue($session->matchesMostRecentlyUsedCredentialWithin(
            $previous,
            new DateTimeImmutable('2026-11-05T01:00:01+00:00'),
            new DateInterval('PT5S')
        ));
        self::assertFalse($session->matchesMostRecentlyUsedCredentialWithin(
            $previous,
            new DateTimeImmutable('2026-11-05T01:00:05+00:00'),
            new DateInterval('PT5S')
        ));
        self::assertFalse($session->isUsableAt(new DateTimeImmutable('2026-11-06T00:00:00+00:00')));
        self::assertTrue($session->revoke()->isRevoked());
        self::assertFalse($session->isRevoked());
    }

    public function test_rotation_cannot_reestablish_a_current_or_previously_used_credential(): void
    {
        $previous = RefreshCredential::fromString(str_repeat('b', 64));
        $session = self::hydrate([
            'usedCredentialDigests' => [$previous->digest()],
            'rotatedAt'             => new DateTimeImmutable('2026-11-05T00:00:00+00:00')
        ]);
        foreach ([RefreshCredential::fromString(str_repeat('a', 64)), $previous] as $credential) {
            try {
                $session->rotate(
                    $credential,
                    new DateTimeImmutable('2026-11-05T01:00:00+00:00'),
                    new DateTimeImmutable('2026-11-06T00:00:00+00:00')
                );
                self::fail('A non-fresh refresh credential was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $session->getRevision());
                self::assertTrue($session->matchesUsedCredential($previous));
            }
        }
    }

    public function test_revision_exhaustion_rejects_rotation_and_revocation_without_replacing_state(): void
    {
        $session = self::hydrate(['revision' => PHP_INT_MAX]);
        foreach (['rotate', 'revoke'] as $transition) {
            try {
                if ($transition === 'rotate') {
                    $session->rotate(
                        RefreshCredential::fromString(str_repeat('b', 64)),
                        new DateTimeImmutable('2026-11-05T01:00:00+00:00'),
                        new DateTimeImmutable('2026-11-06T00:00:00+00:00')
                    );
                } else {
                    $session->revoke();
                }

                self::fail('An exhausted revision was advanced.');
            } catch (InvalidArgumentException) {
                self::assertSame(PHP_INT_MAX, $session->getRevision());
                self::assertFalse($session->isRevoked());
            }
        }
    }

    /** @phpstan-param array<string, mixed> $overrides */
    private static function hydrate(array $overrides): RefreshSession
    {
        $class = new ReflectionClass(RefreshSession::class);
        $session = $class->newInstanceWithoutConstructor();
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $now = new DateTimeImmutable('2026-11-05T00:00:00+00:00');
        $constructor->invokeArgs($session, array_replace([
            'id'                    => RefreshSessionId::generate(),
            'userId'                => UserId::generate(),
            'credentialDigest'      => RefreshCredential::fromString(str_repeat('a', 64))->digest(),
            'usedCredentialDigests' => [],
            'createdAt'             => $now,
            'lastActivityAt'        => $now,
            'rotatedAt'             => null,
            'idleExpiresAt'         => $now->modify('+1 day'),
            'absoluteExpiresAt'     => $now->modify('+2 days'),
            'authenticationVersion' => 1,
            'remembered'            => false,
            'revision'              => 0,
            'revoked'               => false
        ], $overrides));

        return $session;
    }
}
