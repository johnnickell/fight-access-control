<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Admission;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureUnavailable;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use TypeError;

#[CoversClass(FeatureAdmission::class)]
#[CoversClass(FeatureUnavailable::class)]
final class FeatureAdmissionTest extends TestCase
{
    public function test_conjunction_and_fresh_reads_use_captured_exact_permission_identity(): void
    {
        $transaction = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($transaction);
        $permissions = new InMemoryPermissionRepository($transaction);
        $id = PermissionId::generate();
        $permissions->add(Permission::define(
            $id,
            PermissionName::fromString('AGENT_PROFILE_READ'),
            new DateTimeImmutable()
        ));
        $admission = new FeatureAdmission(new FeatureAvailability($features, $permissions));
        $context = new AdmissionEnvironment()->user(permissionId: $id)->resolve();
        $references = FeatureReferences::fromStrings('one', 'two');
        foreach (['one', 'two'] as $name) {
            $features->seed(Feature::reconstitute(
                FeatureId::generate(),
                FeatureName::fromString($name),
                $id,
                FeatureStatus::PREVIEW,
                1
            ));
        }

        self::assertSame($context, $admission->admit($references, $context));
        self::assertCount(2, $features->lookups);
        $second = array_values($features->records)[1];
        $features->seed(Feature::reconstitute($second->getId(), $second->getName(), $id, FeatureStatus::OFF, 2));
        try {
            $admission->admit($references, $context);
            self::fail('Every effective restriction must pass.');
        } catch (FeatureUnavailable) {
            self::assertCount(4, $features->lookups);
        }

        $features->seed(Feature::reconstitute($second->getId(), $second->getName(), $id, FeatureStatus::ON, 3));
        self::assertSame($context, $admission->admit($references, $context));
        self::assertSame(0, $transaction->transactions);
        self::assertSame(0, $features->writes);
    }

    /** @return iterable<string, array{Throwable}> */
    public static function evaluationFaults(): iterable
    {
        yield 'absence is not an evaluation outcome' => [new ResourceNotFound('PRIVATE STORAGE FAILURE')];
        yield 'authentication is not an evaluation outcome' => [new AuthenticationRequired('PRIVATE STORAGE FAILURE')];
        yield 'marker is not an evaluation outcome' => [new FeatureUnavailable('PRIVATE STORAGE FAILURE')];
        yield 'programming fault is retained' => [new TypeError('PRIVATE STORAGE FAILURE')];
    }

    #[DataProvider('evaluationFaults')]
    public function test_evaluation_failure_retains_its_cause_without_impersonating_an_admission_outcome(
        Throwable $failure
    ): void {
        $features = $this->createMock(FeatureRepository::class);
        $features->expects(self::once())->method('getByName')->willThrowException($failure);
        $admission = new FeatureAdmission(new FeatureAvailability($features, new InMemoryPermissionRepository()));
        try {
            $admission->admit(FeatureReferences::fromStrings('protected'), null);
            self::fail('Only an owned false result may become unavailability.');
        } catch (LogicException $logicException) {
            self::assertSame($failure, $logicException->getPrevious());
            self::assertSame('Feature evaluation failed.', $logicException->getMessage());
        }
    }

    public function test_empty_adds_no_restriction_and_unknown_authority_is_not_anonymous(): void
    {
        $transaction = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($transaction);
        $admission = new FeatureAdmission(new FeatureAvailability($features, new InMemoryPermissionRepository()));
        self::assertNull($admission->admit(new FeatureReferences(), null));
        self::assertSame([], $features->lookups);
        $unknown = new SecurityContext($this->createStub(AuthenticatedAuthority::class));
        $this->expectException(LogicException::class);
        $admission->admit(FeatureReferences::fromStrings('protected'), $unknown);
    }
}
