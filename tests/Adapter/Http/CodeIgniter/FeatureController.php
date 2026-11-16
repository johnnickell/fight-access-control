<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use CodeIgniter\HTTP\ResponseInterface;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

#[FeatureFlag('child-gate')]
final class FeatureController extends FeatureParent
{
    #[FeatureFlag('method-gate')]
    public function handle(string $id): ResponseInterface
    {
        return parent::handle($id);
    }
}
