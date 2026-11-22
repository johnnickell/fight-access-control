<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\UnitCoverage;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class Enumeration
{
    public const array CATEGORIES = ['Functional', 'Integration', 'Unit'];

    public static function read(string $tests, string $manifest, bool $inspect): array
    {
        $classes = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['classes'];
        $document = new DOMDocument();
        if (
            !$document->load($tests, LIBXML_NONET) || $document->doctype !== null
            || $document->documentElement?->localName !== 'testSuite'
        ) {
            throw new RuntimeException('Invalid PHPUnit enumeration XML');
        }

        $discovered = [];
        $identities = [];
        foreach ($document->getElementsByTagNameNS('*', 'testClass') as $class) {
            $name = $class->getAttribute('name');
            $entry = $classes[$name] ?? throw new RuntimeException('Unknown enumerated class: '.$name);
            if (!str_ends_with($class->getAttribute('file'), '/'.$entry['file'])) {
                throw new RuntimeException('Class/file identity mismatch: '.$name);
            }

            if (isset($discovered[$name])) {
                throw new RuntimeException('Duplicate enumerated class: '.$name);
            }

            $discovered[$name] = true;
            if (!in_array($entry['category'], self::CATEGORIES, true) || !$entry['reason']) {
                throw new RuntimeException('Incomplete classification: '.$name);
            }

            $methods = self::admitMethods($class, $name, $entry, $identities);
            if (!$inspect && array_diff_key($entry['methods'] ?? [], $methods) !== []) {
                throw new RuntimeException('Stale method classification: '.$name);
            }
        }

        if (!$inspect && array_diff_key($classes, $discovered) !== []) {
            throw new RuntimeException('Classification contains stale or undiscovered classes');
        }

        if ($identities === []) {
            throw new RuntimeException('No expanded tests in PHPUnit enumeration');
        }

        return $identities;
    }

    private static function admitMethods(DOMElement $class, string $name, array $entry, array &$identities): array
    {
        $methods = [];
        foreach ($class->childNodes as $method) {
            if (!$method instanceof DOMElement || $method->localName !== 'testMethod') {
                continue;
            }

            $identity = $method->getAttribute('id');
            $methodName = $method->getAttribute('name');
            if (preg_match('/\A\w+\z/u', $methodName) !== 1) {
                throw new RuntimeException('Malformed enumerated method name');
            }

            $base = $name.'::'.$methodName;
            if ($identity !== $base && !str_starts_with($identity, $base.'#')) {
                throw new RuntimeException('Inconsistent enumerated identity: '.$identity);
            }

            if (isset($identities[$identity])) {
                throw new RuntimeException('Duplicate enumerated identity: '.$identity);
            }

            $category = ($entry['methods'][$methodName] ?? $entry)['category'];
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new RuntimeException('Invalid category');
            }

            $identities[$identity] = $category;
            $methods[$methodName] = true;
        }

        return $methods;
    }
}
