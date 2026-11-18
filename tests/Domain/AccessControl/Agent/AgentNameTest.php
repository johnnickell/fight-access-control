<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentName::class)]
final class AgentNameTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validNames(): iterable
    {
        yield 'mixed edges' => ["\0\t\u{00A0}Deploy / é\u{3000}\r\n", 'Deploy / é'];
        yield 'internal whitespace and case' => [" De\tploy\u{00A0}A  B ", "De\tploy\u{00A0}A  B"];
        yield 'internal nul' => [" A\0B ", "A\0B"];
        yield '120 code points' => ["\u{3000}".str_repeat('é', 120)."\u{00A0}", str_repeat('é', 120)];
        yield 'zero width and bom are names' => [" \u{200B}\u{FEFF} ", "\u{200B}\u{FEFF}"];
        yield 'decomposed remains decomposed' => [" e\u{0301} ", "e\u{0301}"];
        yield 'composed remains composed' => [' é ', 'é'];
        // Literal fixed set, not a Unicode property lookup that can change with runtime data.
        $edges = [
            "\0", "\t", "\n", "\v", "\f", "\r", ' ', "\u{0085}", "\u{00A0}", "\u{1680}",
            "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}", "\u{2006}",
            "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{2028}", "\u{2029}", "\u{202F}",
            "\u{205F}", "\u{3000}"
        ];
        foreach ($edges as $index => $edge) {
            yield 'fixed edge '.$index => [$edge.'X'.$edge, 'X'];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'ascii empty' => [" \t\n\0"];
        yield 'unicode empty' => ["\u{00A0}\u{3000}"];
        yield '121 code points' => [str_repeat('é', 121)];
        yield 'invalid byte' => ["\xFF"];
        yield 'invalid suffix' => ["Valid\xC3"];
    }

    #[DataProvider('validNames')]
    public function test_one_fixed_rule_preserves_the_expected_name(string $input, string $expected): void
    {
        self::assertSame($expected, AgentName::fromString($input)->toString());
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_have_a_safe_domain_failure(string $input): void
    {
        $this->expectException(AgentNameException::class);
        $this->expectExceptionMessage('An Agent name must be non-empty and at most 120 characters long.');
        AgentName::fromString($input);
    }

    public function test_length_does_not_depend_on_the_ambient_multibyte_encoding(): void
    {
        $encoding = mb_internal_encoding();
        try {
            mb_internal_encoding('ISO-8859-1');
            self::assertSame(str_repeat('é', 120), AgentName::fromString(str_repeat('é', 120))->toString());
        } finally {
            mb_internal_encoding($encoding);
        }
    }
}
