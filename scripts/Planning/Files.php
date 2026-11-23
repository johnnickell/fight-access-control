<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\Planning;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class Files
{
    public static function read(string $path): string
    {
        $text = @file_get_contents($path);
        if ($text === false || preg_match('//u', $text) !== 1) {
            throw new RuntimeException("Cannot read UTF-8 Markdown: $path");
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function write(string $path, string $text): void
    {
        if (self::resolve($path) !== $path || @file_put_contents($path, $text) !== strlen($text)) {
            throw new RuntimeException("Cannot write Markdown: $path");
        }
    }

    public static function markdown(string $directory, string $suffix = '.md', array $exclude = []): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $entries = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            $entries,
            static fn (SplFileInfo $file): bool => !in_array($file->getPathname(), $exclude, true)
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    public static function frontmatter(string $path, bool $strict = true): array
    {
        $text = self::read($path);
        if (!str_starts_with($text, "---\n")) {
            if (!$strict) {
                return [];
            }

            throw new RuntimeException('missing frontmatter');
        }

        $parts = explode('---', $text, 3);
        if (count($parts) !== 3) {
            throw new RuntimeException('unterminated frontmatter');
        }

        $fields = [];
        $block = trim($parts[1]);
        foreach ($block === '' ? [] : explode("\n", $block) as $line) {
            $pair = explode(':', $line, 2);
            $key = trim($pair[0]);
            if (count($pair) !== 2 || ($strict && array_key_exists($key, $fields))) {
                if (!$strict) {
                    continue;
                }

                throw new RuntimeException('invalid or duplicate frontmatter field');
            }

            $fields[$key] = trim($pair[1]);
        }

        return $fields;
    }

    // Resolve existing symlinks even when the final target does not exist.
    public static function resolve(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
                $resolved = realpath('/'.implode('/', $parts));
                if ($resolved !== false) {
                    $parts = explode('/', ltrim($resolved, '/'));
                }
            }
        }

        return '/'.implode('/', $parts);
    }

    public static function relative(string $directory, string $path): string
    {
        $source = explode('/', trim($directory, '/'));
        $target = explode('/', trim($path, '/'));
        while ($source !== [] && $target !== [] && $source[0] === $target[0]) {
            array_shift($source);
            array_shift($target);
        }

        return implode('/', [...array_fill(0, count($source), '..'), ...$target]) ?: '.';
    }

    public static function mkdir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Cannot create directory: $path");
        }
    }
}
