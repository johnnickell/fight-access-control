<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Attribute;

use Error;
use Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\Exception\PermissionNameException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(RequiresAgentPermission::class)]
final class RequiresAgentPermissionTest extends TestCase
{
    public function test_method_requirements_are_repeatable_and_retain_canonical_permission_values(): void
    {
        $tool = new class {
            #[RequiresAgentPermission('VIEW_AGENTS')]
            #[RequiresAgentPermission('UPDATE_AGENTS')]
            #[RequiresAgentPermission('VIEW_AGENTS')]
            public function handle(): string
            {
                return 'Metadata alone does not enforce authority.';
            }
        };
        $attributes = new ReflectionMethod($tool, 'handle')->getAttributes(RequiresAgentPermission::class);
        self::assertSame(['VIEW_AGENTS', 'UPDATE_AGENTS', 'VIEW_AGENTS'], array_map(
            static fn($attribute): string => $attribute->newInstance()->getPermissionName()->toString(),
            $attributes
        ));
        self::assertSame('Metadata alone does not enforce authority.', $tool->handle());
    }

    public function test_invalid_names_reject_without_normalization(): void
    {
        $this->expectException(PermissionNameException::class);

        new RequiresAgentPermission(' view_agents ');
    }

    public function test_native_class_target_is_rejected(): void
    {
        $fixture = require __DIR__.'/../Fixture/InvalidAgentPermissionTarget.php.fixture';
        $attribute = new ReflectionClass($fixture)->getAttributes(RequiresAgentPermission::class)[0];
        $this->expectException(Error::class);
        $this->expectExceptionMessage('cannot target class');

        $attribute->newInstance();
    }
}
