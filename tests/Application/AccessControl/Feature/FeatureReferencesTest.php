<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature;

use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferences;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeatureReferences::class)]
#[CoversClass(FeatureName::class)]
#[CoversClass(FeatureNameException::class)]
final class FeatureReferencesTest extends TestCase
{
    public function test_it_combines_discovered_and_explicit_names_as_an_immutable_set(): void
    {
        $discovered = new FeatureReferences(
            FeatureName::fromString('dashboard'),
            FeatureName::fromString('new-checkout'),
            FeatureName::fromString('dashboard')
        );
        $registered = FeatureReferences::fromStrings('agent-tools-v2', 'dashboard', 'agent-tools-v2');
        $combined = $discovered->merge($registered);
        $strings = static fn(FeatureReferences $references): array => array_map(
            static fn(FeatureName $name): string => $name->toString(),
            $references->getNames()
        );

        self::assertSame(['dashboard', 'new-checkout', 'agent-tools-v2'], $strings($combined));
        self::assertSame(['dashboard', 'new-checkout'], $strings($discovered));
        self::assertSame(['agent-tools-v2', 'dashboard'], $strings($registered));
        self::assertSame($strings($combined), $strings($combined->merge($combined)));

        $copy = $combined->getNames();
        array_pop($copy);
        self::assertCount(2, $copy);
        self::assertCount(3, $combined->getNames());
    }

    public function test_empty_reference_collections_are_valid_and_do_not_erase_other_references(): void
    {
        $empty = FeatureReferences::fromStrings();
        $registered = FeatureReferences::fromStrings('dashboard');

        self::assertSame([], $empty->getNames());
        self::assertEquals($registered, $empty->merge($registered));
        self::assertEquals($registered, $registered->merge($empty));
        self::assertEquals($empty, $empty->merge(new FeatureReferences()));
    }

    public function test_invalid_registration_is_not_dropped_or_normalized_among_duplicates(): void
    {
        $this->expectException(FeatureNameException::class);

        FeatureReferences::fromStrings('dashboard', 'dashboard', ' dashboard', 'agent-tools-v2');
    }
}
