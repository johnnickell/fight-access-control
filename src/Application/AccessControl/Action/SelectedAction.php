<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Action;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Class SelectedAction
 *
 * Identifies trusted selected code without constructing or invoking a controller.
 */
final readonly class SelectedAction
{
    /**
     * Constructs SelectedAction
     *
     * @phpstan-param list<ReflectionClass<object>|ReflectionMethod|ReflectionFunction> $declarations
     */
    private function __construct(
        private string $className,
        private string $methodName,
        private ?Closure $closure,
        private array $declarations
    ) {
    }

    /**
     * Creates an action for an explicitly selected public concrete method
     *
     * @param class-string $className
     */
    public static function method(string $className, string $methodName): self
    {
        $class = new ReflectionClass($className);
        if ($class->isInterface() || $class->isTrait() || $class->isAbstract()) {
            throw new InvalidArgumentException('The selected action requires a concrete controller class.');
        }

        $method = $class->getMethod($methodName);
        if (!$method->isPublic() || $method->isAbstract() || $method->isConstructor() || $method->isDestructor()) {
            throw new InvalidArgumentException('The selected action requires a public concrete action method.');
        }

        $declarations = [];
        foreach (self::ancestry($className) as $ancestor) {
            $declarations[] = $ancestor;
            if ($ancestor->hasMethod($methodName)) {
                $parentMethod = $ancestor->getMethod($methodName);
                if ($parentMethod->getDeclaringClass()->getName() === $ancestor->getName()) {
                    $declarations[] = $parentMethod;
                }
            }
        }

        return new self($class->getName(), $method->getName(), null, $declarations);
    }

    /**
     * Returns class and interface ancestry shared with declaration inventory
     *
     * @param class-string $className
     *
     * @return list<ReflectionClass<object>>
     */
    public static function ancestry(string $className): array
    {
        $class = new ReflectionClass($className);
        $hierarchy = [];
        do {
            $hierarchy[] = $class;
            $class = $class->getParentClass();
        } while ($class !== false);

        // Interfaces also carry promises; neither implementation nor override can erase them.
        $classes = [];
        foreach (array_reverse($hierarchy) as $ancestor) {
            foreach ($ancestor->getInterfaces() as $interface) {
                $classes[$interface->getName()] = $interface;
            }

            $classes[$ancestor->getName()] = $ancestor;
        }

        return array_values($classes);
    }

    /**
     * Creates an action for an explicitly selected invokable class
     *
     * @param class-string $className
     */
    public static function invokable(string $className): self
    {
        return self::method($className, '__invoke');
    }

    /**
     * Creates an action for an explicitly selected closure
     */
    public static function closure(Closure $closure): self
    {
        return new self('', '', $closure, [new ReflectionFunction($closure)]);
    }

    /**
     * Returns all applicable class and method declaration sites in ancestry order
     *
     * The output is pure metadata shared by independent declaration capabilities, not an admission decision.
     *
     * @return list<ReflectionClass<object>|ReflectionMethod|ReflectionFunction>
     */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }

    /**
     * Validates that a binding still describes the actually selected action
     */
    public function assertMatches(self $selectedAction): void
    {
        if (
            $this->className !== $selectedAction->className
            || $this->methodName !== $selectedAction->methodName
            || $this->closure !== $selectedAction->closure
        ) {
            throw new InvalidArgumentException('The requirements do not describe the selected action.');
        }
    }
}
