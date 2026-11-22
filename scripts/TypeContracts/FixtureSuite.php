<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\TypeContracts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

final class FixtureSuite
{
    public static function load(string $suite): array
    {
        $document = json_decode(self::read($suite.'/cases.json'), flags: JSON_THROW_ON_ERROR);
        self::require(self::hasFields($document, ['cases']), 'Invalid suite document');
        self::require(is_array($document->cases) && $document->cases !== [], 'Missing or empty fixture cases');
        $ids = [];
        $files = [];
        $kinds = [];
        $cases = [];
        foreach ($document->cases as $case) {
            self::require(self::hasFields($case, ['id', 'kind', 'file', 'diagnostics']), 'Invalid case declaration');
            $id = $case->id;
            self::require(self::hasText($id) && !isset($ids[$id]), 'Missing/duplicate case id');
            $ids[$id] = true;
            self::require(in_array($case->kind, ['positive', 'negative'], true), "$id: invalid case kind");
            $kinds[$case->kind] = true;
            $name = $case->file;
            self::require(is_string($name) && $name !== '' && $name[0] !== '/', "$id: invalid fixture path");
            $file = realpath($suite.'/'.$name);
            self::require(
                $file !== false && str_starts_with($file, rtrim($suite, '/').'/')
                && str_ends_with($file, '.php') && is_file($file),
                "$id: fixture must be a PHP file inside the suite"
            );
            self::require(!isset($files[$file]) && self::hasText(self::read($file)), "$id: duplicate or empty fixture");
            $files[$file] = true;
            self::validateDiagnostics($case);
            $case->file = $file;
            $cases[] = $case;
        }

        self::require(count($kinds) === 2, 'Both positive and negative cases are required');
        $discovered = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($suite, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($entries as $entry) {
            if (!str_ends_with($entry->getFilename(), '.php')) {
                continue;
            }

            $resolved = $entry->getRealPath();
            self::require($resolved !== false, 'Unresolved fixture: '.$entry->getPathname());
            $discovered[$resolved] = true;
        }

        self::require(
            $discovered == $files,
            'Unregistered or missing PHP fixtures: '.implode(
                ', ',
                array_keys(array_diff_key($discovered, $files) + array_diff_key($files, $discovered))
            )
        );

        return $cases;
    }

    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new ContractFailure($message);
        }
    }

    public static function hasFields(mixed $object, array $fields): bool
    {
        return $object instanceof stdClass && count(get_object_vars($object)) === count($fields)
            && array_diff($fields, array_keys(get_object_vars($object))) === [];
    }

    public static function hasText(mixed $value): bool
    {
        return is_string($value) && preg_match('/\S/u', $value) === 1;
    }

    private static function read(string $file): string
    {
        $contents = @file_get_contents($file);
        self::require($contents !== false && preg_match('//u', $contents) === 1, "Cannot read UTF-8 input: $file");

        return $contents;
    }

    private static function validateDiagnostics(stdClass $case): void
    {
        $id = $case->id;
        self::require(is_array($case->diagnostics) && $case->diagnostics !== [], "$id: missing expected diagnostics");
        foreach ($case->diagnostics as $diagnostic) {
            self::require(self::hasFields($diagnostic, ['line', 'identifier', 'message']), "$id: invalid diagnostic");
            self::require(is_int($diagnostic->line) && $diagnostic->line > 0, "$id: invalid diagnostic line");
            self::require(
                self::hasText($diagnostic->identifier) && self::hasText($diagnostic->message),
                "$id: empty diagnostic identity/message"
            );
            $isProbe = $diagnostic->identifier === 'phpstan.dumpType';
            self::require($isProbe === ($case->kind === 'positive'), "$id: incorrect diagnostic kind");
            if ($isProbe) {
                self::require(str_starts_with($diagnostic->message, 'Dumped type: '), "$id: invalid precision probe");
            }
        }
    }
}
