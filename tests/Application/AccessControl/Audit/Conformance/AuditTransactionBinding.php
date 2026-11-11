<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Throwable;

/** Test-only controls; consumer implementations supply actual transactions and independent reopened reads */
interface AuditTransactionBinding
{
    public function execute(): void;

    public function reopenUser(): User;

    /** @return list<AuditEvidence> */
    public function reopenEvidence(): array;

    /** @return array{object, object, object} Owning transaction, business connection, audit connection */
    public function identities(): array;

    public function primaryFailure(): ?Throwable;
}
