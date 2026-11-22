<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\UnitCoverage;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class Enumeration
{
    public const array CATEGORIES = ['Functional', 'Integration', 'Unit'];

    private const string XML_NAMESPACE = 'https://xml.phpunit.de/testSuite';

    public static function read(string $tests, string $manifest): array
    {
        $classes = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['classes'];
        $xml = file_get_contents($tests);
        $document = new DOMDocument();
        if (
            $xml === false || !mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")
            || !$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null
        ) {
            throw new RuntimeException('Invalid PHPUnit enumeration XML');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('p', self::XML_NAMESPACE);

        $discovered = [];
        $identities = [];
        foreach ($xpath->query('/p:testSuite/p:tests/p:testClass') as $class) {
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

            $methods = self::admitMethods($xpath, $class, $name, $entry, $identities);
            if ($methods === [] || array_diff_key($entry['methods'] ?? [], $methods) !== []) {
                throw new RuntimeException('Missing or stale enumerated methods: '.$name);
            }
        }

        if ($identities === [] || array_diff_key($classes, $discovered) !== []) {
            throw new RuntimeException('No expanded tests or stale classes in the PHPUnit enumeration');
        }

        return $identities;
    }

    private static function admitMethods(
        DOMXPath $xpath,
        DOMElement $class,
        string $name,
        array $entry,
        array &$identities
    ): array {
        $methods = [];
        foreach ($xpath->query('p:testMethod', $class) as $method) {
            $identity = $method->getAttribute('id');
            $methodName = $method->getAttribute('name');
            $base = $name.'::'.$methodName;
            if ($methodName === '' || ($identity !== $base && !str_starts_with($identity, $base.'#'))) {
                throw new RuntimeException('Inconsistent enumerated identity: '.$identity);
            }

            if (isset($identities[$identity])) {
                throw new RuntimeException('Duplicate enumerated identity: '.$identity);
            }

            $category = ($entry['methods'][$methodName] ?? $entry)['category'];
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new RuntimeException('Invalid category for '.$identity);
            }

            $identities[$identity] = $category;
            $methods[$methodName] = true;
        }

        return $methods;
    }
}
