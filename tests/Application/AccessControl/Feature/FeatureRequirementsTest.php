<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature;

use Error;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture\ChildFeatureAction;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture\FeatureActionContract;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture\FeatureActionTrait;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture\ParentFeatureAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use stdClass;
use Throwable;

#[CoversClass(SelectedAction::class)]
#[CoversClass(FeatureRequirements::class)]
#[CoversClass(FeatureFlag::class)]
#[CoversClass(FeatureReferences::class)]
#[CoversClass(FeatureName::class)]
#[CoversClass(FeatureNameException::class)]
final class FeatureRequirementsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidActions(): iterable
    {
        yield 'missing class' => ['MissingSelectedAction', 'execute'];
        yield 'missing method' => [ChildFeatureAction::class, 'missing'];
        yield 'protected method' => [ChildFeatureAction::class, 'protectedAction'];
        yield 'constructor' => [ChildFeatureAction::class, '__construct'];
        yield 'abstract class' => [ParentFeatureAction::class, 'execute'];
        yield 'interface' => [FeatureActionContract::class, 'execute'];
    }

    public function test_overridden_requirements_accumulate_without_controller_construction_or_lookup(): void
    {
        $action = SelectedAction::method(ChildFeatureAction::class, 'execute');
        $requirements = new FeatureRequirements($action, 'route', 'duplicate', 'route');
        self::assertSame([
            'parent-class', 'duplicate', 'parent-method', 'child-class', 'child-method', 'route'
        ], $this->names($requirements, $action));
        self::assertSame($this->names($requirements, $action), $this->names(
            $requirements,
            SelectedAction::method(ChildFeatureAction::class, 'EXECUTE')
        ));
        $copy = $action->getDeclarations();
        array_pop($copy);
        self::assertCount(count($copy) + 1, $action->getDeclarations());
    }

    public function test_inherited_and_invokable_actions_preserve_parent_gates(): void
    {
        $inherited = SelectedAction::method(ChildFeatureAction::class, 'inherited');
        self::assertSame(['parent-class', 'duplicate', 'inherited-method', 'child-class'], $this->names(
            new FeatureRequirements($inherited),
            $inherited
        ));
        $invokable = SelectedAction::invokable(ChildFeatureAction::class);
        self::assertSame(
            ['parent-class', 'duplicate', 'parent-invokable', 'child-class', 'child-invokable'],
            $this->names(new FeatureRequirements($invokable), $invokable)
        );
    }

    public function test_interface_promises_are_cumulative_and_cannot_be_erased_by_implementation(): void
    {
        $controller = new class extends ParentFeatureAction implements FeatureActionContract {
            public function __construct()
            {
            }
        };
        $action = SelectedAction::method($controller::class, 'execute');
        self::assertSame(
            ['parent-class', 'duplicate', 'parent-method', 'interface-class', 'interface-method'],
            $this->names(new FeatureRequirements($action), $action)
        );
    }

    public function test_an_override_with_no_declaration_cannot_weaken_parent_requirements(): void
    {
        $controller = new class extends ParentFeatureAction {
            public function __construct()
            {
            }

            public function execute(): string
            {
                return 'no child declarations';
            }
        };
        $action = SelectedAction::method($controller::class, 'execute');
        self::assertSame(
            ['parent-class', 'duplicate', 'parent-method'],
            $this->names(new FeatureRequirements($action), $action)
        );
    }

    public function test_explicit_static_trait_alias_uses_php_resolved_selected_method_metadata(): void
    {
        $controller = new class {
            use FeatureActionTrait {
                traitAction as aliasAction;
            }
        };
        $action = SelectedAction::method($controller::class, 'aliasAction');
        self::assertSame(['trait-method'], $this->names(new FeatureRequirements($action), $action));
    }

    public function test_empty_and_explicit_closure_requirements_are_not_permission_decisions(): void
    {
        $closure = static fn(): string => 'direct call';
        $action = SelectedAction::closure($closure);
        self::assertSame([], $this->names(new FeatureRequirements($action), $action));
        self::assertSame(['route'], $this->names(new FeatureRequirements($action, 'route', 'route'), $action));
        self::assertSame('direct call', $closure());
        self::assertSame([], SelectedAction::ancestry(stdClass::class)[0]->getAttributes(FeatureFlag::class));
    }

    public function test_action_mismatch_never_returns_an_empty_or_unrelated_binding(): void
    {
        $bound = SelectedAction::method(ChildFeatureAction::class, 'execute');
        $requirements = new FeatureRequirements($bound);
        $this->expectException(InvalidArgumentException::class);
        $requirements->getReferences(SelectedAction::method(ChildFeatureAction::class, 'inherited'));
    }

    public function test_distinct_closures_with_identical_code_do_not_share_bindings(): void
    {
        $requirements = new FeatureRequirements(SelectedAction::closure(static fn(): bool => true), 'route');
        $this->expectException(InvalidArgumentException::class);
        $requirements->getReferences(SelectedAction::closure(static fn(): bool => true));
    }

    #[DataProvider('invalidActions')]
    public function test_unresolvable_or_unsupported_selected_actions_fail_instead_of_losing_gates(
        string $class,
        string $method
    ): void {
        try {
            SelectedAction::method($class, $method);
            self::fail('Invalid selected actions cannot produce metadata.');
        } catch (InvalidArgumentException | ReflectionException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public function test_every_registration_is_validated_before_effective_deduplication(): void
    {
        $action = SelectedAction::method(ChildFeatureAction::class, 'execute');
        $this->expectException(FeatureNameException::class);
        new FeatureRequirements($action, 'duplicate', 'duplicate', ' duplicate');
    }

    public function test_malformed_repeated_metadata_is_instantiated_even_after_a_valid_duplicate(): void
    {
        $fixture = require __DIR__.'/Fixture/MalformedFeatureActions.php.fixture';
        foreach ($fixture as $class) {
            try {
                new FeatureRequirements(SelectedAction::method($class, 'execute'), 'duplicate');
                self::fail('Malformed metadata cannot disappear during deduplication.');
            } catch (Error | FeatureNameException | InvalidArgumentException $exception) {
                self::assertInstanceOf(Throwable::class, $exception);
            }
        }
    }

    public function test_unsupported_native_closure_attribute_is_not_silently_ignored(): void
    {
        $closure = require __DIR__.'/Fixture/InvalidFeatureFunction.php.fixture';
        $this->expectException(Error::class);
        new FeatureRequirements(SelectedAction::closure($closure));
    }

    public function test_direct_php_calls_remain_unintercepted(): void
    {
        $controller = new ReflectionClass(ChildFeatureAction::class)->newInstanceWithoutConstructor();
        self::assertSame('direct child execution', $controller->execute());
    }

    /** @return list<string> */
    private function names(FeatureRequirements $requirements, SelectedAction $action): array
    {
        return array_map(
            static fn(FeatureName $name): string => $name->toString(),
            $requirements->getReferences($action)->getNames()
        );
    }
}
