<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling;

use Closure;
use RuntimeException;
use stdClass;

final readonly class BuildEvidence
{
    public function __construct(private string $root, private array $outputs)
    {
    }

    public static function tree(string $path, ?Closure $skip = null, array $seen = []): string
    {
        self::require(!isset($seen[$path]), 'Cyclic input link');
        $seen[$path] = true;
        $info = lstat($path);
        $mode = $info['mode'] & 07777;
        if (is_link($path)) {
            $target = realpath($path);
            self::require(
                $target !== false && str_starts_with($target, getcwd().'/'),
                'Input link target is not visible inside the selected checkout'
            );

            return self::digest(['link', $mode, readlink($path), self::tree($target, seen: $seen)]);
        }

        if (is_dir($path)) {
            $children = [];
            $names = scandir($path, SCANDIR_SORT_NONE);
            sort($names, SORT_STRING);
            foreach ($names as $name) {
                $child = $path.'/'.$name;
                if ($name !== '.' && $name !== '..' && !($skip && $skip($child))) {
                    $children[] = [$name, self::tree($child, $skip, $seen)];
                }
            }

            return self::digest(['directory', $mode, $children]);
        }

        self::require(is_file($path), 'Special input file disables caching');

        return self::digest(['file', $mode, hash_file('sha256', $path)]);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function read(string $path): stdClass
    {
        return json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
    }

    public static function write(string $path, array $value): void
    {
        self::require(!is_link($path), 'Refusing aliased runtime configuration: '.$path);
        $temporary = $path.'.tmp';
        $stream = fopen($temporary, 'xb');
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            self::require(fwrite($stream, $json) === strlen($json), 'Incomplete build evidence write');
        } finally {
            fclose($stream);
        }

        rename($temporary, $path);
    }

    public function planning(): string
    {
        clearstatcache(true);

        return self::tree($this->root.'/planning');
    }

    public function source(array $tracked): string
    {
        clearstatcache(true);
        $excluded = [
            '.git', '.runs', 'var', '.idea', '.vscode', '.DS_Store', 'graphify-out',
            '__pycache__', '.phpcs-cache', '.phpunit.cache', '.phpunit.result.cache', ...$this->outputs
        ];
        $skip = function (string $path) use ($excluded): bool {
            $relative = substr($path, strlen($this->root) + 1);
            $parts = explode('/', $relative);
            $name = basename($path);
            if (
                in_array($parts[0], $excluded, true) || in_array($relative, $this->outputs, true)
                || in_array($name, ['.DS_Store', '__pycache__'], true)
                || in_array($relative, ['client/node_modules/.cache', 'client/node_modules/.vite-temp'], true)
            ) {
                return true;
            }

            return $parts[0] === 'planning' && is_file($path) && !is_link($path) && str_ends_with($name, '.md')
                && !in_array($name, ['CONVENTIONS.md', 'AGENTS.md', 'CLAUDE.md', 'SKILL.md'], true)
                && !str_ends_with($name, '_TEMPLATE.md') && array_intersect(['agents', 'adr'], $parts) === [];
        };
        self::require(is_file($this->root.'/vendor/autoload.php'), 'Installed dependencies are missing');
        $runtime = new stdClass();
        foreach ($tracked as $name) {
            if ($name !== '') {
                $runtime->{$name} = self::tree($this->root.'/'.$name);
            }
        }

        return self::digest([self::tree($this->root, $skip), $runtime]);
    }

    public function artifacts(): stdClass
    {
        clearstatcache(true);
        $result = new stdClass();
        foreach ($this->outputs as $name) {
            $result->{$name} = self::tree($this->root.'/'.$name);
        }

        return $result;
    }

    // Keep the schema-2 wire encoding, including sorted object keys and separators.
    private static function encode(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);
            $items = [];
            foreach ($fields as $key => $item) {
                $items[] = self::encode((string) $key).': '.self::encode($item);
            }

            return '{'.implode(', ', $items).'}';
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map(self::encode(...), $value)).']';
        }

        return str_replace("\x7f", '\\u007f', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
