<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Attribute;

use Error;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

#[CoversClass(FeatureFlag::class)]
#[CoversClass(FeatureName::class)]
#[CoversClass(FeatureNameException::class)]
final class FeatureFlagTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        foreach (['class', 'property', 'constant', 'parameter', 'repeated-first', 'repeated-second'] as $target) {
            yield $target => [$target];
        }
    }

    public function test_native_method_metadata_exposes_only_a_name_and_does_not_enforce_access(): void
    {
        $fixture = new class {
            #[FeatureFlag('new-checkout')]
            public function checkout(): string
            {
                return 'invoked without an availability check';
            }
        };
        $attributes = new ReflectionMethod($fixture, 'checkout')->getAttributes(FeatureFlag::class);
        self::assertCount(1, $attributes);
        $declaration = $attributes[0]->newInstance();
        self::assertSame('new-checkout', $declaration->getName()->toString());
        self::assertEquals(['name'], array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass($declaration)->getProperties()
        ));
        self::assertSame('invoked without an availability check', $fixture->checkout());
    }

    public function test_native_instantiation_rejects_an_invalid_name(): void
    {
        $fixture = new class {
            #[FeatureFlag('New-checkout')]
            public function checkout(): void
            {
            }
        };
        $attribute = new ReflectionMethod($fixture, 'checkout')->getAttributes(FeatureFlag::class)[0];
        $this->expectException(FeatureNameException::class);

        $attribute->newInstance();
    }

    #[DataProvider('invalidTargets')]
    public function test_native_instantiation_rejects_wrong_targets_and_repeatability(string $target): void
    {
        $fixture = require __DIR__.'/../Fixture/InvalidFeatureTargets.php.fixture';
        $class = new ReflectionClass($fixture);
        $reflection = match ($target) {
            'class'     => $class,
            'property'  => $class->getProperty('value'),
            'constant'  => $class->getReflectionConstant('NAME'),
            'parameter' => new ReflectionParameter([$fixture, 'repeated'], 'argument'),
            default     => $class->getMethod('repeated')
        };
        self::assertNotFalse($reflection);
        $attributes = $reflection->getAttributes(FeatureFlag::class);
        $index = 0;
        if ($target === 'repeated-second') {
            $index = 1;
        }

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Attribute "'.FeatureFlag::class.'"');

        $attributes[$index]->newInstance();
    }
}
