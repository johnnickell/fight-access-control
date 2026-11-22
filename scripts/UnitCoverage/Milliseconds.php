<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\UnitCoverage;

use RuntimeException;

final class Milliseconds
{
    // CPython's default decimal conversion limit applies to input and rendered totals.
    private const int DIGIT_LIMIT = 4300;

    // Starts of the Unicode 15.1 Decimal_Number blocks used by the pinned Python runtime.
    private const array DIGIT_ZEROES = [
        0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66,
        0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0DE6, 0x0E50, 0x0ED0, 0x0F20,
        0x1040, 0x1090, 0x17E0, 0x1810, 0x1946, 0x19D0, 0x1A80, 0x1A90,
        0x1B50, 0x1BB0, 0x1C40, 0x1C50, 0xA620, 0xA8D0, 0xA900, 0xA9D0,
        0xA9F0, 0xAA50, 0xABF0, 0xFF10, 0x104A0, 0x10D30, 0x11066, 0x110F0,
        0x11136, 0x111D0, 0x112F0, 0x11450, 0x114D0, 0x11650, 0x116C0, 0x11730,
        0x118E0, 0x11950, 0x11C50, 0x11D50, 0x11DA0, 0x11F50, 0x16A60, 0x16AC0,
        0x16B50, 0x1D7CE, 0x1D7D8, 0x1D7E2, 0x1D7EC, 0x1D7F6, 0x1E140, 0x1E2F0,
        0x1E4F0, 0x1E950, 0x1FBF0
    ];

    public static function parse(string $value): string
    {
        // Unlike trim(), these are the whitespace characters accepted by int(str, 10).
        $space = '[\x09-\x0D\x20\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
        $value = preg_replace('/\A'.$space.'++|'.$space.'++\z/u', '', $value);
        $value = preg_replace_callback('/[^\x00-\x7F]/u', self::asciiDigit(...), $value);
        if (preg_match('/\A[+\-]?[0-9](?:_?[0-9])*+\z/', $value) !== 1) {
            throw new RuntimeException('Invalid test duration');
        }

        $digits = str_replace('_', '', ltrim($value, '+-'));
        if (strlen($digits) > self::DIGIT_LIMIT) {
            throw new RuntimeException('Test duration exceeds decimal conversion limit');
        }

        $digits = ltrim($digits, '0');
        if ($digits !== '' && str_starts_with($value, '-')) {
            throw new RuntimeException('Negative test duration');
        }

        return $digits === '' ? '0' : $digits;
    }

    public static function add(string $left, string $right): string
    {
        // Use native arithmetic on the ordinary gate path, never a rounded float.
        if (strlen($left) <= 9 && strlen($right) <= 9) {
            return (string) ((int) $left + (int) $right);
        }

        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $carry = 0;
        $reversed = '';
        while ($i >= 0 || $j >= 0 || $carry !== 0) {
            $sum = $carry + ($i >= 0 ? (int) $left[$i--] : 0) + ($j >= 0 ? (int) $right[$j--] : 0);
            $reversed .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        if (strlen($reversed) > self::DIGIT_LIMIT) {
            throw new RuntimeException('Total test duration exceeds decimal conversion limit');
        }

        return strrev($reversed);
    }

    private static function asciiDigit(array $match): string
    {
        $codepoint = mb_ord($match[0], 'UTF-8');
        foreach (self::DIGIT_ZEROES as $zero) {
            if ($codepoint >= $zero && $codepoint < $zero + 10) {
                return (string) ($codepoint - $zero);
            }
        }

        throw new RuntimeException('Invalid test duration');
    }
}
