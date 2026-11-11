<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\User;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EnableUserHandler::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(User::class)]
final class InMemoryAuditTransactionConformanceTest extends AuditTransactionConformance
{
    protected function binding(string $fault = 'none'): AuditTransactionBinding
    {
        return new InMemoryAuditTransactionBinding($fault);
    }
}
