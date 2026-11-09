<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Value\Identifier\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnonymousId::class)]
#[CoversClass(SystemId::class)]
final class FixedActorIdentitiesTest extends TestCase
{
    /**
     * @phpstan-param class-string<AnonymousId>|class-string<SystemId> $identityClass
     */
    #[DataProvider('identityClasses')]
    public function test_generation_and_value_reconstruction_preserve_the_fixed_kind(string $identityClass): void
    {
        $id = $identityClass::generate();
        $second = $identityClass::generate();
        $parsed = $identityClass::fromString(Uuid::NIL);
        $reconstructed = $id::fromString($id->toString());

        self::assertInstanceOf(UniqueId::class, $id);
        self::assertSame($identityClass, $id::class);
        self::assertSame(Uuid::NIL, $id->toString());
        self::assertSame(Uuid::NIL, (string) $id);
        self::assertSame(Uuid::NIL, $id->jsonSerialize());
        self::assertSame('"'.Uuid::NIL.'"', json_encode($id, JSON_THROW_ON_ERROR));
        self::assertNotSame($id, $second);
        foreach ([$id, $second, $parsed, $reconstructed] as $sameKind) {
            self::assertSame($identityClass, $sameKind::class);
            self::assertTrue($id->equals($sameKind));
            self::assertTrue($sameKind->equals($id));
            self::assertSame($id->hashValue(), $sameKind->hashValue());
            self::assertSame(0, $id->compareTo($sameKind));
        }
    }

    public function test_identical_uuid_text_does_not_erase_actor_kind(): void
    {
        $identities = [
            SystemId::generate(),
            AnonymousId::generate(),
            UserId::fromString(Uuid::NIL),
            AgentId::fromString(Uuid::NIL)
        ];

        foreach ($identities as $left) {
            foreach ($identities as $right) {
                self::assertSame(Uuid::NIL, $right->toString());
                self::assertSame($left::class === $right::class, $left->equals($right));
                if ($left::class !== $right::class) {
                    self::assertNotSame($left->hashValue(), $right->hashValue());
                }
            }

            self::assertFalse($left->equals(Uuid::NIL));
            self::assertFalse($left->equals(null));
            self::assertFalse($left->equals(Uuid::parse(Uuid::NIL)));
        }
    }

    /**
     * @phpstan-param class-string<AnonymousId>|class-string<SystemId> $identityClass
     */
    #[DataProvider('invalidValues')]
    public function test_construction_and_string_hydration_reject_invalid_values(
        string $identityClass,
        string $value
    ): void {
        $this->expectException(DomainException::class);

        $identityClass::fromString($value);
    }

    /**
     * @phpstan-return iterable<string, array{class-string<AnonymousId>|class-string<SystemId>}>
     */
    public static function identityClasses(): iterable
    {
        yield 'system' => [SystemId::class];
        yield 'anonymous' => [AnonymousId::class];
    }

    /**
     * @phpstan-return iterable<string, array{class-string<AnonymousId>|class-string<SystemId>, string}>
     */
    public static function invalidValues(): iterable
    {
        $values = [
            'ordinary UUID'       => '12345678-1234-4234-8234-123456789abc',
            'almost nil'          => '00000000-0000-0000-0000-000000000001',
            'empty'               => '',
            'garbage'             => 'not-a-uuid',
            'short'               => '00000000-0000-0000-0000-00000000000',
            'long'                => Uuid::NIL.'0',
            'hex'                 => str_repeat('0', 32),
            'leading whitespace'  => ' '.Uuid::NIL,
            'trailing whitespace' => Uuid::NIL."\n",
            'nul'                 => Uuid::NIL."\0",
            'braced nil'          => '{'.Uuid::NIL.'}',
            'URN nil'             => 'urn:uuid:'.Uuid::NIL,
            'embedded prefix'     => '00000000-uuid:0000-0000-0000-000000000000'
        ];
        foreach (self::identityClasses() as $kind => [$identityClass]) {
            foreach ($values as $name => $value) {
                yield $kind.' '.$name => [$identityClass, $value];
            }
        }
    }
}
