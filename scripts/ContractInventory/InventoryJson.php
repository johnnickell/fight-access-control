<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\ContractInventory;

use RuntimeException;
use stdClass;

final class InventoryJson
{
    public static function load(string $path): stdClass
    {
        return json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    public static function save(string $path, stdClass $value): void
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }

        $temporary = $path.'.tmp';
        $stream = fopen($temporary, 'wb');
        try {
            // A complete report is tens of MB. Serialize one boundary at a time.
            $fields = (array) $value;
            ksort($fields, SORT_STRING);
            $separator = "{\n";
            foreach ($fields as $key => $item) {
                self::write($stream, $separator.'  '.self::canonical($key).': ');
                $separator = ",\n";
                if ($key !== 'boundaries' || $item === []) {
                    self::write($stream, self::pretty($item, 2));
                    continue;
                }

                $rowSeparator = "[\n";
                foreach ($item as $row) {
                    self::write($stream, $rowSeparator.'    '.self::pretty($row, 4));
                    $rowSeparator = ",\n";
                }

                self::write($stream, "\n  ]");
            }

            self::write($stream, "\n}\n");
        } finally {
            fclose($stream);
        }

        rename($temporary, $path);
    }

    private static function pretty(mixed $value, int $indent): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        $json = json_encode(self::sorted($value), $flags);

        return preg_replace_callback(
            '/\n */',
            static fn (array $match): string => "\n".str_repeat(' ', $indent + intdiv(strlen($match[0]) - 1, 2)),
            str_replace("\x7f", '\\u007f', $json)
        );
    }

    private static function write($stream, string $text): void
    {
        if (fwrite($stream, $text) !== strlen($text)) {
            throw new RuntimeException('Incomplete inventory write');
        }
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);

            return (object) array_map(self::sorted(...), $fields);
        }

        return is_array($value) ? array_map(self::sorted(...), $value) : $value;
    }

    private static function canonical(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);
            $entries = [];
            foreach ($fields as $key => $item) {
                $entries[] = self::canonical((string) $key).':'.self::canonical($item);
            }

            return '{'.implode(',', $entries).'}';
        }

        if (is_array($value)) {
            return '['.implode(',', array_map(self::canonical(...), $value)).']';
        }

        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        // Python's canonical JSON pads exponents and uses scientific notation starting at 1e16.
        if (is_float($value)) {
            if (!str_contains($json, 'e') && abs($value) >= 1e16) {
                $digits = ltrim(explode('.', $json)[0], '-');
                $fraction = rtrim(substr($digits, 1), '0');

                $mantissa = $digits[0].($fraction === '' ? '' : '.'.$fraction);

                return ($value < 0 ? '-' : '').$mantissa.'e+'.(strlen($digits) - 1);
            }

            return preg_replace(['/\.0e/', '/e-([0-9])$/'], ['e', 'e-0$1'], $json);
        }

        return str_replace("\x7f", '\\u007f', $json);
    }
}
