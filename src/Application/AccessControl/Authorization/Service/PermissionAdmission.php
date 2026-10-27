<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Application\AccessControl\Authorization\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\PermissionDeclaration;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use LogicException;

/**
 * Class PermissionAdmission
 *
 * Checks coarse target requirements without granting consumer record or relationship scope.
 */
final readonly class PermissionAdmission
{
    /**
     * Returns the selected short-request snapshot after complete conjunctive admission
     *
     * Missing declarations are broken composition, not public targets.
     */
    public function admit(?PermissionDeclaration $declaration, ?RequestAuthority $authority): ?SecurityContext
    {
        if ($declaration === null) {
            throw new LogicException('A target permission declaration is required.');
        }

        if ($declaration->mode === AdmissionMode::PUBLIC) {
            return null;
        }

        if ($authority === null) {
            throw new AuthenticationRequired('Request authentication is required.');
        }

        $context = $authority->resolve();
        foreach ($declaration->requirements as $requirement) {
            if (!$context->hasPermission($requirement)) {
                throw new PermissionDenied('Target permission is required.');
            }
        }

        return $context;
    }
}
