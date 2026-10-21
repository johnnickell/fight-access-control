<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class RecoveryAuthority
 *
 * Identifies the immutable User security values authorizing recovery.
 */
final readonly class RecoveryAuthority
{
    /**
     * Constructs RecoveryAuthority
     */
    public function __construct(private int $authenticationVersion, private int $canonicalEmailRevision)
    {
        if ($authenticationVersion < 1 || $canonicalEmailRevision < 0) {
            throw new DomainException('The recovery authority is invalid.');
        }
    }

    /**
     * Returns the issuing authentication version
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Returns the issuing canonical-email revision
     */
    public function getCanonicalEmailRevision(): int
    {
        return $this->canonicalEmailRevision;
    }

    /**
     * Returns whether both security values match
     */
    public function equals(self $other): bool
    {
        return $this->authenticationVersion === $other->authenticationVersion
            && $this->canonicalEmailRevision === $other->canonicalEmailRevision;
    }
}
