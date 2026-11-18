<?php

declare(strict_types=1);

namespace Config;

/**
 * Selects native core service definitions directly in isolated tests, without service-file discovery
 *
 * Each service used by the adapter is injected before access. This is test-owned consumer configuration,
 * not a framework bootstrap, replacement cache implementation or production configuration.
 */
final class Services extends \CodeIgniter\Config\Services
{
}
