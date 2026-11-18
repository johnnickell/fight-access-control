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
use ReflectionFunction;
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
        foreach (['property', 'constant', 'parameter'] as $target) {
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

    public function test_native_function_target_is_rejected_on_instantiation(): void
    {
        $function = require __DIR__.'/../Fixture/InvalidFeatureFunction.php.fixture';
        $attribute = new ReflectionFunction($function)->getAttributes(FeatureFlag::class)[0];
        $this->expectException(Error::class);
        $this->expectExceptionMessage('cannot target function');

        $attribute->newInstance();
    }

    public function test_native_name_retains_the_validated_boundary_without_normalization(): void
    {
        $name = 'a'.str_repeat('1', 127);
        $declaration = new FeatureFlag($name);
        self::assertInstanceOf(FeatureName::class, $declaration->getName());
        self::assertSame($name, $declaration->getName()->toString());
        $this->expectException(FeatureNameException::class);

        new FeatureFlag($name.'1');
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

    public function test_native_class_and_repeated_method_targets_are_validated_by_construction(): void
    {
        $fixture = require __DIR__.'/../Fixture/InvalidFeatureTargets.php.fixture';
        $class = new ReflectionClass($fixture);
        $declaration = $class->getAttributes(FeatureFlag::class)[0]->newInstance();
        self::assertSame('dashboard', $declaration->getName()->toString());
        $names = [];
        foreach ($class->getMethod('repeated')->getAttributes(FeatureFlag::class) as $attribute) {
            $names[] = $attribute->newInstance()->getName()->toString();
        }

        self::assertSame(['dashboard', 'new-checkout'], $names);
    }

    #[DataProvider('invalidTargets')]
    public function test_native_instantiation_rejects_wrong_targets(string $target): void
    {
        $fixture = require __DIR__.'/../Fixture/InvalidFeatureTargets.php.fixture';
        $class = new ReflectionClass($fixture);
        $reflection = match ($target) {
            'property'  => $class->getProperty('value'),
            'constant'  => $class->getReflectionConstant('NAME'),
            'parameter' => new ReflectionParameter([$fixture, 'repeated'], 'argument'),
            default     => $class->getMethod('repeated')
        };
        self::assertNotFalse($reflection);
        $attributes = $reflection->getAttributes(FeatureFlag::class);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Attribute "'.FeatureFlag::class.'"');

        $attributes[0]->newInstance();
    }
}
