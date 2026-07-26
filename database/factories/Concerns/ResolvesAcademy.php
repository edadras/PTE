<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Domain\Tenancy\TenantContext;
use Database\Factories\AcademyFactory;

/**
 * Factories for tenant-scoped models need to behave two ways at once:
 *
 *  - inside a tenant, produce records for *that* tenant, so isolation tests
 *    actually exercise isolation rather than silently minting a fresh academy
 *    per record;
 *  - outside one, stand on their own so a unit test can call
 *    `Model::factory()->create()` without ceremony.
 *
 * `->for($academy)` still overrides either behaviour.
 */
trait ResolvesAcademy
{
    protected function resolveAcademy(): int|AcademyFactory
    {
        return TenantContext::idOrNull() ?? AcademyFactory::new();
    }
}
