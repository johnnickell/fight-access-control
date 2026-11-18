<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission;

use Error;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionRequirements;
use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Domain\AccessControl\Permission\Exception\PermissionNameException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\Fixture\ChildPermissionAction;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\Fixture\ParentPermissionAction;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Throwable;

#[CoversClass(RequiresPermission::class)]
#[CoversClass(PermissionRequirements::class)]
#[CoversClass(PermissionDeclaration::class)]
#[CoversClass(PermissionName::class)]
#[CoversClass(PermissionNameException::class)]
#[CoversClass(SelectedAction::class)]
#[CoversClass(PermissionAdmission::class)]
#[CoversClass(FeatureRequirements::class)]
#[CoversClass(FeatureFlag::class)]
final class PermissionRequirementsTest extends TestCase
{
    public function test_attribute_and_trusted_route_requirements_accumulate_without_controller_construction(): void
    {
        $action = SelectedAction::method(ChildPermissionAction::class, 'execute');
        $requirements = new PermissionRequirements($action);
        self::assertSame(AdmissionMode::PROTECTED, $requirements->getDeclaration($action)?->mode);
        self::assertSame([
            'PARENT_CLASS', 'DUPLICATE', 'PARENT_METHOD', 'CHILD_CLASS', 'CHILD_METHOD'
        ], $this->names($requirements, $action));
        $withRoute = new PermissionRequirements($action, $this->protected('DUPLICATE', 'ROUTE'));
        self::assertSame([
            'PARENT_CLASS', 'DUPLICATE', 'PARENT_METHOD', 'CHILD_CLASS', 'CHILD_METHOD', 'ROUTE'
        ], $this->names($withRoute, SelectedAction::method(ChildPermissionAction::class, 'EXECUTE')));
    }

    public function test_inherited_and_invokable_actions_keep_complete_parent_requirements(): void
    {
        $inherited = SelectedAction::method(ChildPermissionAction::class, 'inherited');
        self::assertSame(['PARENT_CLASS', 'DUPLICATE', 'INHERITED', 'CHILD_CLASS'], $this->names(
            new PermissionRequirements($inherited),
            $inherited
        ));
        $invokable = SelectedAction::invokable(ChildPermissionAction::class);
        self::assertSame([
            'PARENT_CLASS', 'DUPLICATE', 'PARENT_INVOKABLE', 'CHILD_CLASS', 'CHILD_INVOKABLE'
        ], $this->names(new PermissionRequirements($invokable), $invokable));
    }

    public function test_undeclared_override_cannot_weaken_parent_requirements(): void
    {
        $controller = new class extends ParentPermissionAction {
            public function __construct()
            {
            }

            public function execute(): string
            {
                return 'direct';
            }
        };
        $action = SelectedAction::method($controller::class, 'execute');
        self::assertSame(['PARENT_CLASS', 'DUPLICATE', 'PARENT_METHOD'], $this->names(
            new PermissionRequirements($action),
            $action
        ));
        self::assertSame('direct', $controller->execute());
    }

    public function test_explicit_closure_binding_and_absence_preserve_all_modes(): void
    {
        $closure = static fn(): string => 'direct';
        $action = SelectedAction::closure($closure);
        self::assertNull(new PermissionRequirements($action)->getDeclaration($action));
        foreach (AdmissionMode::cases() as $mode) {
            $names = [];
            if ($mode === AdmissionMode::PROTECTED) {
                $names = [PermissionName::fromString('ROUTE')];
            }

            $route = new PermissionDeclaration($mode, $names);
            self::assertSame($route, new PermissionRequirements($action, $route)->getDeclaration($action));
        }

        self::assertSame(['ROUTE'], $this->names(
            new PermissionRequirements($action, $this->protected('ROUTE')),
            $action
        ));
        self::assertSame('direct', $closure());
    }

    public function test_missing_declaration_remains_an_admission_configuration_error(): void
    {
        $action = SelectedAction::closure(static fn(): bool => true);
        $this->expectException(LogicException::class);
        new PermissionAdmission()->admit(new PermissionRequirements($action)->getDeclaration($action), null);
    }

    public function test_attribute_output_uses_existing_admission_without_metadata_time_authority_resolution(): void
    {
        $environment = new AdmissionEnvironment();
        $authority = $environment->user();
        $controller = new #[RequiresPermission('AGENT_PROFILE_READ')] class {
            public function execute(): void
            {
            }
        };
        $action = SelectedAction::method($controller::class, 'execute');
        $declaration = new PermissionRequirements($action)->getDeclaration($action);
        self::assertSame(0, $environment->resolutions);
        self::assertNotNull(new PermissionAdmission()->admit($declaration, $authority));
        self::assertSame(1, $environment->resolutions);
        $additional = new PermissionRequirements($action, $this->protected('OTHER_PERMISSION'));
        $this->expectException(PermissionDenied::class);
        new PermissionAdmission()->admit($additional->getDeclaration($action), $authority);
    }

    public function test_permission_metadata_needs_no_feature_construction_or_evaluation(): void
    {
        $controller = new #[FeatureFlag('INVALID FEATURE')] #[RequiresPermission('READ')] class {
            public function execute(): void
            {
            }
        };
        $action = SelectedAction::method($controller::class, 'execute');
        self::assertSame(['READ'], $this->names(new PermissionRequirements($action), $action));
    }

    public function test_public_permission_policy_can_independently_have_feature_gates(): void
    {
        $controller = new #[FeatureFlag('separate')] class {
            public function execute(): void
            {
            }
        };
        $action = SelectedAction::method($controller::class, 'execute');
        $public = new PermissionDeclaration(AdmissionMode::PUBLIC);
        self::assertSame($public, new PermissionRequirements($action, $public)->getDeclaration($action));
        $features = new FeatureRequirements($action)->getReferences($action);
        self::assertSame('separate', $features->getNames()[0]->toString());
    }

    /** @return iterable<string, array{AdmissionMode}> */
    public static function conflictingModes(): iterable
    {
        yield 'public' => [AdmissionMode::PUBLIC];
        yield 'authenticated only' => [AdmissionMode::AUTHENTICATED];
    }

    #[DataProvider('conflictingModes')]
    public function test_explicit_modes_cannot_erase_attributes(AdmissionMode $mode): void
    {
        $action = SelectedAction::method(ChildPermissionAction::class, 'execute');
        $this->expectException(InvalidArgumentException::class);
        new PermissionRequirements($action, new PermissionDeclaration($mode));
    }

    public function test_binding_to_another_method_or_closure_rejects_instead_of_falling_back(): void
    {
        $requirements = new PermissionRequirements(SelectedAction::method(ChildPermissionAction::class, 'execute'));
        $this->expectException(InvalidArgumentException::class);
        $requirements->getDeclaration(SelectedAction::method(ChildPermissionAction::class, 'inherited'));
    }

    public function test_distinct_closures_cannot_reuse_a_protected_binding(): void
    {
        $requirements = new PermissionRequirements(
            SelectedAction::closure(static fn(): bool => true),
            $this->protected('ROUTE')
        );
        $this->expectException(InvalidArgumentException::class);
        $requirements->getDeclaration(SelectedAction::closure(static fn(): bool => true));
    }

    public function test_unresolvable_selected_action_has_no_empty_fallback(): void
    {
        $this->expectException(ReflectionException::class);
        new PermissionRequirements(SelectedAction::method(ChildPermissionAction::class, 'missing'));
    }

    public function test_all_repeated_metadata_is_instantiated_before_effective_deduplication(): void
    {
        $classes = require __DIR__.'/Fixture/MalformedPermissionActions.php.fixture';
        foreach ($classes as $case => $class) {
            try {
                new PermissionRequirements(SelectedAction::method($class, 'execute'), $this->protected('DUPLICATE'));
                self::fail('Malformed metadata cannot disappear: '.$case);
            } catch (Error | PermissionNameException | InvalidArgumentException $exception) {
                self::assertInstanceOf(Throwable::class, $exception, $case);
            }
        }
    }

    public function test_unsupported_function_placement_rejects_during_native_instantiation(): void
    {
        $closure = require __DIR__.'/Fixture/InvalidPermissionFunction.php.fixture';
        $this->expectException(Error::class);
        new PermissionRequirements(SelectedAction::closure($closure));
    }

    public function test_native_property_parameter_and_constant_placements_reject(): void
    {
        $controller = require __DIR__.'/Fixture/InvalidPermissionTargets.php.fixture';
        $class = new ReflectionClass($controller);
        $sites = [
            $class->getProperty('property'),
            $class->getMethod('execute')->getParameters()[0],
            $class->getReflectionConstant('VALUE')
        ];
        foreach ($sites as $site) {
            try {
                $site->getAttributes(RequiresPermission::class)[0]->newInstance();
                self::fail('Unsupported Attribute placement must reject.');
            } catch (Error $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{AdmissionMode, array<array-key, mixed>}> */
    public static function invalidRawDeclarations(): iterable
    {
        $name = PermissionName::fromString('READ');
        yield 'duplicates' => [AdmissionMode::PROTECTED, [$name, $name]];
        yield 'non-list' => [AdmissionMode::PROTECTED, ['key' => $name]];
        yield 'wrong type' => [AdmissionMode::PROTECTED, ['READ']];
        yield 'empty protected' => [AdmissionMode::PROTECTED, []];
        yield 'public with requirements' => [AdmissionMode::PUBLIC, [$name]];
        yield 'authenticated with requirements' => [AdmissionMode::AUTHENTICATED, [$name]];
    }

    /** @phpstan-param array<array-key, mixed> $names */
    #[DataProvider('invalidRawDeclarations')]
    public function test_raw_route_validation_rejects_before_any_effective_merge(
        AdmissionMode $mode,
        array $names
    ): void {
        $action = SelectedAction::method(ChildPermissionAction::class, 'execute');
        $this->expectException(InvalidArgumentException::class);
        new PermissionRequirements($action, new PermissionDeclaration($mode, $names));
    }

    public function test_attribute_only_protection_still_requires_authentication(): void
    {
        $action = SelectedAction::method(ChildPermissionAction::class, 'execute');
        $this->expectException(AuthenticationRequired::class);
        new PermissionAdmission()->admit(new PermissionRequirements($action)->getDeclaration($action), null);
    }

    public function test_direct_php_invocation_remains_unenforced(): void
    {
        $controller = new ReflectionClass(ChildPermissionAction::class)->newInstanceWithoutConstructor();
        self::assertSame('direct child execution', $controller->execute());
        self::assertSame('direct invocation', $controller());
    }

    private function protected(string ...$names): PermissionDeclaration
    {
        return new PermissionDeclaration(AdmissionMode::PROTECTED, array_map(PermissionName::fromString(...), $names));
    }

    /** @return list<string> */
    private function names(PermissionRequirements $requirements, SelectedAction $action): array
    {
        return array_map(
            static fn(PermissionName $name): string => $name->toString(),
            $requirements->getDeclaration($action)->requirements ?? []
        );
    }
}
