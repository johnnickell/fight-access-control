<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use DateTimeImmutable;
use Fight\AccessControl\Adapter\Middleware\Psr15\FeatureMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\PermissionMiddleware;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthentication;
use Fight\AccessControl\Adapter\Middleware\Psr15\RequestAuthenticationState;
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
use Fight\AccessControl\Application\AccessControl\Feature\FeatureRequirements;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Test\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionEnvironment;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

#[CoversClass(PermissionMiddleware::class)]
#[CoversClass(FeatureMiddleware::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(RequestAuthenticationState::class)]
final class IsolatedAdmissionTest extends TestCase
{
    public function test_permissions_share_one_lazy_authority_across_nested_gates_and_reset_afterward(): void
    {
        $environment = new AdmissionEnvironment();
        $selections = 0;
        $authentication = new RequestAuthentication(
            static function () use ($environment, &$selections): RequestAuthority {
                ++$selections;

                return $environment->user();
            }
        );
        $gate = new PermissionMiddleware(new PermissionDeclaration(AdmissionMode::PROTECTED, [
            PermissionName::fromString('AGENT_PROFILE_READ')
        ]), $authentication);
        $request = $this->request(true);
        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->with($request)->willReturn($response);
        $nested = $this->createStub(RequestHandlerInterface::class);
        $nested->method('handle')->willReturnCallback(static fn(ServerRequestInterface $next): ResponseInterface =>
            $gate->process($next, $handler));

        self::assertSame($response, $gate->process($request, $nested));
        self::assertSame(1, $selections);
        self::assertSame($response, $gate->process($request, $handler));
        self::assertSame(2, $selections);
    }

    public function test_public_declaration_never_selects_authority_and_removes_copied_context(): void
    {
        $calls = 0;
        $gate = new PermissionMiddleware(
            new PermissionDeclaration(AdmissionMode::PUBLIC),
            static function () use (&$calls): null {
                ++$calls;

                return null;
            }
        );
        $request = $this->createMock(ServerRequestInterface::class);
        $cleared = $this->createStub(ServerRequestInterface::class);
        $request->expects(self::never())->method('getAttribute');
        $request->expects(self::once())->method('withoutAttribute')->with(SecurityContext::class)
            ->willReturn($cleared);
        $request->expects(self::never())->method('withAttribute');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($cleared)
            ->willReturn($this->createStub(ResponseInterface::class));

        $gate->process($request, $handler);
        self::assertSame(0, $calls);
    }

    /** @return iterable<string, array{Throwable, class-string<Throwable>}> */
    public static function faults(): iterable
    {
        yield 'authentication refusal' => [new AuthenticationRequired('refused'), AuthenticationRequired::class];
        yield 'unknown provider failure' => [new RuntimeException('private'), LogicException::class];
    }

    #[DataProvider('faults')]
    public function test_authority_selection_preserves_intent_without_misclassifying_provider_faults(
        Throwable $failure,
        string $expected
    ): void {
        $state = new RequestAuthenticationState(static function () use ($failure): never {
            throw $failure;
        });
        $this->expectException($expected);

        $state->authority();
    }

    public function test_selected_action_fault_remains_an_internal_fault(): void
    {
        $action = SelectedAction::closure(static function (): void {
        });
        $gate = new FeatureMiddleware(
            new FeatureRequirements($action),
            static function (): never {
                throw new RuntimeException('selection unavailable');
            },
            new RequestAuthentication(static fn(): null => null),
            $this->features(FeatureStatus::ON)
        );
        $this->expectException(LogicException::class);

        $gate->process($this->request(), $this->createStub(RequestHandlerInterface::class));
    }

    /** @return iterable<string, array{FeatureStatus, bool}> */
    public static function featureStates(): iterable
    {
        yield 'on' => [FeatureStatus::ON, true];
        yield 'off' => [FeatureStatus::OFF, false];
    }

    #[DataProvider('featureStates')]
    public function test_feature_restriction_passes_context_or_translates_only_ordinary_unavailability(
        FeatureStatus $status,
        bool $allowed
    ): void {
        $action = SelectedAction::closure(static function (): void {
        });
        $gate = new FeatureMiddleware(
            new FeatureRequirements($action, 'rollout'),
            static fn(): SelectedAction => $action,
            new RequestAuthentication(static fn(): null => null),
            $this->features($status)
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $handler->expects($allowed ? self::once() : self::never())->method('handle')->willReturn($response);
        if (!$allowed) {
            $this->expectException(ResourceNotFound::class);
        }

        self::assertSame($response, $gate->process($this->request(), $handler));
    }

    #[DataProvider('faults')]
    public function test_feature_authority_failures_keep_their_own_categories(
        Throwable $failure,
        string $expected
    ): void {
        $action = SelectedAction::closure(static function (): void {
        });
        $environment = new AdmissionEnvironment();
        $gate = new FeatureMiddleware(
            new FeatureRequirements($action, 'rollout'),
            static fn(): SelectedAction => $action,
            new RequestAuthentication(static fn(): RequestAuthority => $environment->user(fault: $failure)),
            $this->features(FeatureStatus::ON)
        );
        $this->expectException($expected);

        $gate->process($this->request(), $this->createStub(RequestHandlerInterface::class));
    }

    private function request(bool $admitted = false): ServerRequestInterface
    {
        // This controlled interface proves coordination/attribute calls, not native request immutability.
        if ($admitted) {
            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects(self::atLeastOnce())->method('withAttribute')
                ->with(SecurityContext::class, self::isInstanceOf(SecurityContext::class))->willReturnSelf();
            $request->expects(self::atLeastOnce())->method('withoutAttribute')
                ->with(SecurityContext::class)->willReturnSelf();
        } else {
            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('withoutAttribute')->willReturnSelf();
            $request->method('withAttribute')->willReturnSelf();
        }

        return $request;
    }

    private function features(FeatureStatus $status): FeatureAdmission
    {
        $id = PermissionId::generate();
        $permission = Permission::define($id, PermissionName::fromString('ROLLOUT'), new DateTimeImmutable());
        $permissionRepository = $this->createStub(PermissionRepository::class);
        $permissionRepository->method('getById')->willReturn($permission);
        $featureRepository = $this->createStub(FeatureRepository::class);
        $featureRepository->method('getByName')->willReturn(Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('rollout'),
            $id,
            $status,
            1
        ));

        return new FeatureAdmission(new FeatureAvailability($featureRepository, $permissionRepository));
    }
}
