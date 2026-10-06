<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeatureName::class)]
#[CoversClass(FeatureNameException::class)]
final class FeatureNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validNames(): iterable
    {
        $names = ['a', 'dashboard', 'new-checkout', 'agent-tools-v2', 'a0', 'a-1', 'a-1-b2', str_repeat('z', 128)];
        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield '129 bytes' => [str_repeat('a', 129)];
        yield 'leading digit' => ['1-feature'];
        yield 'uppercase' => ['Feature'];
        yield 'all uppercase' => ['FEATURE'];
        yield 'underscore' => ['new_feature'];
        yield 'leading hyphen' => ['-feature'];
        yield 'trailing hyphen' => ['feature-'];
        yield 'repeated hyphen' => ['new--feature'];
        yield 'leading space' => [' feature'];
        yield 'trailing space' => ['feature '];
        yield 'internal space' => ['new feature'];
        yield 'tab' => ["feature\t"];
        yield 'newline' => ["feature\n"];
        yield 'CRLF' => ["feature\r\n"];
        yield 'null byte' => ["feature\0"];
        yield 'nonbreaking space' => ["feature\u{00A0}"];
        yield 'zero width space' => ["fea\u{200B}ture"];
        yield 'accent' => ['café'];
        yield 'combining accent' => ["cafe\u{0301}"];
        yield 'fullwidth letters' => ['ｆｅａｔｕｒｅ'];
        yield 'Unicode hyphen' => ['new‐feature'];
        yield 'invalid UTF-8' => ["feature\xFF"];
        yield 'dot' => ['new.feature'];
        yield 'slash' => ['new/feature'];
        yield 'hyphen only' => ['-'];
    }

    #[DataProvider('validNames')]
    public function test_names_are_preserved_and_compared_by_value(string $value): void
    {
        $name = FeatureName::fromString($value);

        self::assertSame($value, $name->toString());
        self::assertSame($value, (string) $name);
        self::assertTrue($name->equals(FeatureName::fromString($value)));
        self::assertFalse($name->equals(FeatureName::fromString('different-name')));
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected_without_normalization(string $value): void
    {
        $this->expectException(FeatureNameException::class);

        FeatureName::fromString($value);
    }
}
