<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Middleware\Psr15;

use Closure;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use LogicException;
use Throwable;

/**
 * Class RequestAuthenticationState
 *
 * Holds lazy trusted selection only for one active middleware chain.
 */
final class RequestAuthenticationState
{
    public ?SecurityContext $context = null;

    private bool $selected = false;

    private ?RequestAuthority $authority = null;

    /**
     * Constructs RequestAuthenticationState
     *
     * @phpstan-param Closure(): ?RequestAuthority $select
     */
    public function __construct(private readonly Closure $select)
    {
    }

    /**
     * Returns one actual provider selection without replaying its authentication
     */
    public function authority(): ?RequestAuthority
    {
        if (!$this->selected) {
            try {
                $this->authority = ($this->select)();
            } catch (AuthenticationRequired $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw new LogicException('Request authority selection failed.', previous: $failure);
            }

            $this->selected = true;
        }

        return $this->authority;
    }
}
