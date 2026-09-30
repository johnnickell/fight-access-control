<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;

/**
 * Consumer-bindable setup for package provisioning and preparation behavior.
 * Bind the same repository instances to both operations; implement failure hooks using owned disposable data.
 */
interface FeaturePreparationEnvironment
{
    /** @param list<string> $registrations */
    public function references(array $registrations, bool $complete = true): void;

    public function provision(?string $default): void;

    public function validate(): FeaturePreparationResult;

    public function definition(string $name): ?Feature;

    public function stored(string $name, FeatureStatus $status, string $permission): Feature;

    public function failNextInsertion(string $name): void;

    public function seedWinnerOnInsertion(string $name, FeatureStatus $status, string $permission): Feature;

    public function clearInsertionHook(): void;

    public function failNextCreationPublication(): void;

    public function writes(): int;
}
