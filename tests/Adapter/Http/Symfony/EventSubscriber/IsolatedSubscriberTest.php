<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\FeatureSubscriber;
use Fight\AccessControl\Adapter\Http\Symfony\EventSubscriber\PermissionSubscriber;
use Fight\AccessControl\Adapter\Http\Symfony\RequestAuthentication;
use Fight\AccessControl\Adapter\Http\Symfony\SelectedController;
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
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Throwable;

#[CoversClass(PermissionSubscriber::class)]
#[CoversClass(FeatureSubscriber::class)]
#[CoversClass(RequestAuthentication::class)]
#[CoversClass(SelectedController::class)]
final class IsolatedSubscriberTest extends TestCase
{
    public function test_request_scoped_authentication_ignores_copied_attributes_and_selects_once(): void
    {
        $environment = new AdmissionEnvironment();
        $authority = $environment->user();
        $calls = 0;
        $authentication = new RequestAuthentication(static function () use ($authority, &$calls): RequestAuthority {
            ++$calls;

            return $authority;
        });
        $event = $this->event();
        $event->getRequest()->attributes->set(SecurityContext::class, 'forged');
        self::assertNull($authentication->authority($event, false));
        self::assertFalse($event->getRequest()->attributes->has(SecurityContext::class));
        self::assertSame($authority, $authentication->authority($event, true));
        $authentication->authority($event, true);
        $context = $authority->resolve();
        $authentication->publish($event, $context);
        $authentication->publish($event, null);
        self::assertSame($context, $event->getRequest()->attributes->get(SecurityContext::class));
        self::assertSame(1, $calls);
        $authentication->authority($this->event(), true);
        self::assertSame(2, $calls);
    }

    /** @return iterable<string, array{Throwable, class-string<Throwable>}> */
    public static function failures(): iterable
    {
        yield 'known refusal' => [new AuthenticationRequired('refused'), AuthenticationRequired::class];
        yield 'operational failure' => [new RuntimeException('unavailable'), LogicException::class];
    }

    #[DataProvider('failures')]
    public function test_authority_selection_distinguishes_refusal_from_fault(
        Throwable $failure,
        string $expected
    ): void {
        $authentication = new RequestAuthentication(static function () use ($failure): never {
            throw $failure;
        });
        $this->expectException($expected);

        $authentication->authority($this->event(), true);
    }

    public function test_permission_subscriber_uses_trusted_target_and_publishes_admitted_context(): void
    {
        $event = $this->event();
        $matcher = $this->matcher($event, [
            PermissionDeclaration::class => new PermissionDeclaration(AdmissionMode::AUTHENTICATED)
        ]);
        $environment = new AdmissionEnvironment();
        $subscriber = new PermissionSubscriber($matcher, static fn(): RequestAuthority => $environment->user());

        self::assertSame(['kernel.request' => ['onKernelRequest', 16]], $subscriber::getSubscribedEvents());
        $subscriber->onKernelRequest($event);
        self::assertInstanceOf(SecurityContext::class, $event->getRequest()->attributes->get(SecurityContext::class));
        $event->getRequest()->attributes->set('_route', 'forged');
        $this->expectException(LogicException::class);
        $subscriber->onKernelRequest($event);
    }

    public function test_malformed_permission_declaration_fails_before_authority(): void
    {
        $event = $this->event();
        $subscriber = new PermissionSubscriber(
            $this->matcher($event, [PermissionDeclaration::class => 'forged']),
            new RequestAuthentication(static function (): never {
                self::fail('Malformed target must not authenticate.');
            })
        );
        $this->expectException(LogicException::class);

        $subscriber->onKernelRequest($event);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function controllers(): iterable
    {
        yield 'method text' => [IsolatedController::class.'::handle', 'handle'];
        yield 'method class array' => [[IsolatedController::class, 'handle'], 'handle'];
        yield 'method object array' => [[new IsolatedController(), 'handle'], 'handle'];
        yield 'invokable class' => [IsolatedController::class, '__invoke'];
        yield 'invokable object' => [new IsolatedController(), '__invoke'];
        yield 'closure' => [static function (): void {
        }, 'closure'];
    }

    #[DataProvider('controllers')]
    public function test_controller_forms_resolve_without_a_kernel(mixed $controller, string $method): void
    {
        if ($controller instanceof Closure) {
            $expected = SelectedAction::closure($controller);
        } else {
            $expected = SelectedAction::method(IsolatedController::class, $method);
        }

        $selected = new SelectedController()->action($controller);
        $expected->assertMatches($selected);
        self::assertNotEmpty($selected->getDeclarations());
    }

    public function test_unsupported_controller_does_not_become_an_action(): void
    {
        $this->expectException(LogicException::class);

        new SelectedController()->action(['invalid']);
    }

    public function test_feature_subscriber_checks_whole_target_and_resolved_controller(): void
    {
        $event = $this->event();
        $action = new SelectedController()->action($event->getRequest()->attributes->get('_controller'));
        $subscriber = new FeatureSubscriber(
            $this->matcher($event, [FeatureRequirements::class => new FeatureRequirements($action, 'rollout')]),
            new RequestAuthentication(static fn(): null => null),
            $this->features(FeatureStatus::ON)
        );
        self::assertSame(['onKernelController', 256], $subscriber::getSubscribedEvents()['kernel.controller']);
        $subscriber->onKernelRequest($event);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber->onKernelController(new ControllerEvent(
            $kernel,
            [new IsolatedController(), 'handle'],
            $event->getRequest(),
            HttpKernelInterface::MAIN_REQUEST
        ));
        $this->expectException(LogicException::class);
        $subscriber->onKernelController(new ControllerEvent(
            $kernel,
            static function (): void {
            },
            $event->getRequest(),
            HttpKernelInterface::MAIN_REQUEST
        ));
    }

    public function test_feature_off_is_the_ordinary_concealed_outcome(): void
    {
        $event = $this->event();
        $action = new SelectedController()->action($event->getRequest()->attributes->get('_controller'));
        $subscriber = new FeatureSubscriber(
            $this->matcher($event, [FeatureRequirements::class => new FeatureRequirements($action, 'rollout')]),
            new RequestAuthentication(static fn(): null => null),
            $this->features(FeatureStatus::OFF)
        );
        $this->expectException(ResourceNotFound::class);

        $subscriber->onKernelRequest($event);
    }

    #[DataProvider('failures')]
    public function test_feature_resolution_preserves_known_authentication_failure_but_wraps_faults(
        Throwable $failure,
        string $expected
    ): void {
        $event = $this->event();
        $action = new SelectedController()->action($event->getRequest()->attributes->get('_controller'));
        $environment = new AdmissionEnvironment();
        $subscriber = new FeatureSubscriber(
            $this->matcher($event, [FeatureRequirements::class => new FeatureRequirements($action, 'rollout')]),
            new RequestAuthentication(static fn(): RequestAuthority => $environment->user(fault: $failure)),
            $this->features(FeatureStatus::ON)
        );
        $this->expectException($expected);

        $subscriber->onKernelRequest($event);
    }

    public function test_malformed_feature_target_is_an_internal_fault(): void
    {
        $event = $this->event();
        $subscriber = new FeatureSubscriber(
            $this->matcher($event, [FeatureRequirements::class => 'invalid']),
            new RequestAuthentication(static fn(): null => null),
            $this->features(FeatureStatus::ON)
        );
        $this->expectException(LogicException::class);

        $subscriber->onKernelRequest($event);
    }

    public function test_feature_route_mismatch_is_not_a_permission_or_availability_refusal(): void
    {
        $event = $this->event();
        $matcher = $this->matcher($event, []);
        $event->getRequest()->attributes->set('_route', 'copied-other-route');
        $subscriber = new FeatureSubscriber(
            $matcher,
            new RequestAuthentication(static fn(): null => null),
            $this->features(FeatureStatus::ON)
        );
        $this->expectException(LogicException::class);

        $subscriber->onKernelRequest($event);
    }

    private function event(): RequestEvent
    {
        $request = new Request(attributes: [
            '_route'      => 'target',
            '_controller' => IsolatedController::class.'::handle'
        ]);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );
    }

    /** @param array<string, mixed> $extra */
    private function matcher(RequestEvent $event, array $extra): RequestMatcherInterface
    {
        $matcher = $this->createStub(RequestMatcherInterface::class);
        $matcher->method('matchRequest')->willReturn($event->getRequest()->attributes->all() + $extra);

        return $matcher;
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
