<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryActivationOriginConformanceTest extends ActivationOriginConformance
{
    protected function repository(): ActivationGrantRepository
    {
        return new InMemoryActivationGrantRepository();
    }

    protected function restart(ActivationGrantRepository $repository): ActivationGrantRepository
    {
        assert($repository instanceof InMemoryActivationGrantRepository);

        return $repository->restart();
    }
}
